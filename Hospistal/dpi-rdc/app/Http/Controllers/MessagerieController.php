<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Chat : messages instantanés entre agents du réseau local.
 *
 * Pas de serveur de diffusion à installer : la page relit la conversation
 * toutes les quelques secondes, l'icône du menu relit le nombre de messages
 * non lus et émet un signal sonore à l'arrivée d'un nouveau.
 */
class MessagerieController extends Controller
{
    public function index(Request $request, ?User $avec = null): View
    {
        $moi = $request->user();
        if ($avec && $avec->id === $moi->id) {
            $avec = null;
        }

        if ($avec) {
            Message::where('expediteur_id', $avec->id)->where('destinataire_id', $moi->id)
                ->whereNull('lu_at')->update(['lu_at' => now()]);
        }

        return view('messagerie.index', [
            'conversations' => $this->conversations($moi),
            'avec' => $avec,
            'messages' => $avec ? Message::entre($moi->id, $avec->id)->orderBy('created_at')->limit(300)->get() : collect(),
            'agents' => User::where('is_active', true)->where('id', '!=', $moi->id)->orderBy('nom')->get(),
        ]);
    }

    public function envoyer(Request $request, User $avec): JsonResponse|RedirectResponse
    {
        $donnees = $request->validate(['contenu' => 'required|string|max:5000'], ['contenu.required' => 'Écrivez un message.']);
        abort_if($avec->id === $request->user()->id, 422, 'On ne s\'écrit pas à soi-même.');

        $message = Message::create([
            'expediteur_id' => $request->user()->id,
            'destinataire_id' => $avec->id,
            'contenu' => trim($donnees['contenu']),
        ]);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'id' => $message->id])
            : redirect()->route('messagerie.conversation', $avec);
    }

    /** Le fil, relu par la page ouverte (et marqué lu). */
    public function fil(Request $request, User $avec): JsonResponse
    {
        $moi = $request->user();
        Message::where('expediteur_id', $avec->id)->where('destinataire_id', $moi->id)
            ->whereNull('lu_at')->update(['lu_at' => now()]);

        $messages = Message::entre($moi->id, $avec->id)->orderBy('created_at')->limit(300)->get();

        return response()->json([
            'html' => view('messagerie._fil', ['messages' => $messages, 'moi' => $moi])->render(),
            'dernier' => $messages->last()?->id,
        ]);
    }

    /** Nombre de messages non lus : sert au voyant et au son du menu. */
    public function nonLus(Request $request): JsonResponse
    {
        return response()->json(['non_lus' => $this->compterNonLus($request->user())]);
    }

    public function supprimer(Request $request, Message $message): JsonResponse|RedirectResponse
    {
        $moi = $request->user();
        if ($message->expediteur_id === $moi->id) {
            $message->update(['supprime' => true, 'contenu' => '']);
        } elseif ($message->destinataire_id === $moi->id) {
            $message->update(['masque_destinataire' => true]);
        } else {
            abort(403);
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public static function compterNonLus(User $user): int
    {
        return Message::where('destinataire_id', $user->id)->whereNull('lu_at')
            ->where('supprime', false)->where('masque_destinataire', false)->count();
    }

    /** Conversations récentes : le dernier message échangé avec chaque agent. */
    private function conversations(User $moi): Collection
    {
        $derniers = Message::query()
            ->selectRaw("CASE WHEN expediteur_id = ? THEN destinataire_id ELSE expediteur_id END AS autre_id, MAX(created_at) AS dernier_at", [$moi->id])
            ->where(fn ($q) => $q->where('expediteur_id', $moi->id)
                ->orWhere(fn ($w) => $w->where('destinataire_id', $moi->id)->where('masque_destinataire', false)))
            // PostgreSQL regroupe sur l'alias de la colonne calculée.
            ->groupBy('autre_id')
            ->orderByDesc('dernier_at')
            ->limit(50)
            ->get();

        $agents = User::whereIn('id', $derniers->pluck('autre_id'))->get()->keyBy('id');
        $nonLus = Message::where('destinataire_id', $moi->id)->whereNull('lu_at')->where('masque_destinataire', false)
            ->selectRaw('expediteur_id, count(*) as n')->groupBy('expediteur_id')->pluck('n', 'expediteur_id');

        return $derniers->map(function ($d) use ($agents, $nonLus, $moi) {
            $dernier = Message::entre($moi->id, $d->autre_id)->latest()->first();

            return (object) [
                'agent' => $agents[$d->autre_id] ?? null,
                'dernier' => $dernier,
                'non_lus' => (int) ($nonLus[$d->autre_id] ?? 0),
            ];
        })->filter(fn ($c) => $c->agent)->values();
    }
}
