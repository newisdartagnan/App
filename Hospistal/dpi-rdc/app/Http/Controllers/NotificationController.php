<?php

namespace App\Http\Controllers;

use App\Models\NotificationInterne;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $onglet = $request->query('onglet', 'toutes');
        $nonLuesSeulement = $request->query('lu') === '0';

        $compteurs = [];
        foreach (['toutes', 'labo', 'imagerie', 'pharmacie'] as $cle) {
            $compteurs[$cle] = NotificationInterne::query()
                ->pourUtilisateur($user)->actives()->nonLues()
                ->when($cle !== 'toutes', fn ($q) => $q->where('service', $cle))
                ->count();
        }

        $notifications = NotificationInterne::query()
            ->pourUtilisateur($user)
            ->actives()
            ->when(in_array($onglet, ['labo', 'imagerie', 'pharmacie'], true),
                fn ($q) => $q->where('service', $onglet))
            ->when($nonLuesSeulement, fn ($q) => $q->where('lu', false))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('notifications.index', compact('notifications', 'onglet', 'nonLuesSeulement', 'compteurs'));
    }

    public function marquerLue(Request $request, NotificationInterne $notification): RedirectResponse
    {
        $notification->update(['lu' => true, 'read_at' => now()]);

        // Le bouton « Voir » marque comme lu puis ouvre la page concernée
        if ($request->boolean('ouvrir') && $notification->lien()) {
            return redirect($notification->lien());
        }

        return back();
    }

    public function toutMarquerLues(Request $request): RedirectResponse
    {
        NotificationInterne::query()
            ->pourUtilisateur($request->user())
            ->actives()
            ->nonLues()
            ->when($request->filled('service'), fn ($q) => $q->where('service', $request->service))
            ->update(['lu' => true, 'read_at' => now()]);

        return back()->with('success', 'Notifications marquées comme lues.');
    }

    public function archiver(NotificationInterne $notification): RedirectResponse
    {
        $notification->update(['archive' => true]);

        return back();
    }
}
