<?php

namespace App\Services;

use App\Models\BulletinStockSang;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\PocheSang;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le réseau des banques de sang, entre hôpitaux réellement distants.
 *
 * L'écran « Réseau » lisait le stock des autres dans la base locale : cela
 * ne dit la vérité que si tous les hôpitaux partagent une seule base. Or
 * chaque hôpital tourne chez lui, avec sa propre base. Entre deux villes,
 * l'écran était vide — ou pire, plausible et faux.
 *
 * Chaque maison publie donc un bulletin et reçoit en retour ceux des autres,
 * par le même aller-retour : un seul appel, ce qui compte sur une liaison
 * qui coupe. Ce qui voyage n'est qu'un décompte — jamais un nom de donneur,
 * jamais un numéro de poche, jamais un patient. On apprend qu'il y a trois
 * O− à Kikwit et à quel numéro appeler ; c'est Kikwit qui appellera ses
 * donneurs, pas nous.
 */
class ReseauSangService
{
    /**
     * Au-delà, on considère que la liaison a échoué.
     *
     * Court volontairement : cet appel se fait pendant qu'un infirmier
     * attend devant l'écran, à trois heures du matin.
     */
    private const DELAI_SECONDES = 20;

    public function __construct(private readonly BanqueSangService $banque) {}

    /** Le réseau est-il configuré ? Sans point de rendez-vous, il n'y a pas de réseau. */
    public function configure(): bool
    {
        return filled($this->pointDeRendezVous());
    }

    public function pointDeRendezVous(): ?string
    {
        $url = (string) config('dpi.central_api_url');

        return $url === '' ? null : rtrim($url, '/');
    }

    /**
     * Le bulletin de cette maison : des nombres, et de quoi la rappeler.
     *
     * Rien d'autre ne doit entrer dans ce tableau. C'est le seul endroit du
     * code où des données quittent l'hôpital pour une machine qui n'est pas
     * la sienne.
     *
     * @return array<string, mixed>
     */
    public function bulletinLocal(Establishment $maison): array
    {
        $poches = PocheSang::where('establishment_id', $maison->id)
            ->where('statut', 'disponible')
            ->get()
            ->filter->estDelivrable();

        $stock = collect(PocheSang::PRODUITS)
            ->keys()
            ->mapWithKeys(fn (string $produit) => [
                $produit => collect(PocheSang::GROUPES)
                    ->mapWithKeys(fn (string $groupe) => [
                        $groupe => $poches->where('type_produit', $produit)->where('groupe_sanguin', $groupe)->count(),
                    ])
                    ->filter()
                    ->all(),
            ])
            ->filter()
            ->all();

        $donneurs = DonneurSang::where('establishment_id', $maison->id)
            ->where('est_eligible', true)
            ->get()
            ->filter->peutDonnerMaintenant();

        return [
            'etablissement_code' => $maison->code,
            'nom' => $maison->name,
            'ville' => $maison->ville,
            'province' => $maison->province,
            'telephone' => $maison->telephone,
            'stock' => $stock,
            'donneurs' => collect(PocheSang::GROUPES)
                ->mapWithKeys(fn (string $g) => [$g => $donneurs->where('groupe_sanguin', $g)->count()])
                ->filter()
                ->all(),
            'publie_le' => now()->toIso8601String(),
        ];
    }

    /**
     * Publier notre bulletin et rapporter ceux des autres.
     *
     * @return array{publie: bool, recus: int, connus: int, message: string}
     */
    public function echanger(Establishment $maison): array
    {
        if (! $this->configure()) {
            return ['publie' => false, 'recus' => 0, 'connus' => 0, 'message' => 'Aucun point de rendez-vous configuré (CENTRAL_API_URL).'];
        }

        if (blank($maison->central_sync_token)) {
            return ['publie' => false, 'recus' => 0, 'connus' => 0, 'message' => 'Cet établissement n\'a pas de jeton de réseau.'];
        }

        // Une maison qui s'est retirée du réseau ne publie plus rien, mais
        // continue de recevoir : se retirer, ce n'est pas se priver.
        $partage = $this->banque->partageSonStock($maison->id);
        $bulletin = $partage ? $this->bulletinLocal($maison) : null;

        try {
            $reponse = Http::withToken($maison->central_sync_token)
                ->acceptJson()
                ->timeout(self::DELAI_SECONDES)
                ->post($this->pointDeRendezVous().'/api/banque-sang/bulletins', [
                    'etablissement_code' => $maison->code,
                    'bulletin' => $bulletin,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Réseau sang injoignable', ['erreur' => $e->getMessage()]);

            return ['publie' => false, 'recus' => 0, 'connus' => 0, 'message' => 'Le point de rendez-vous ne répond pas.'];
        }

        if ($reponse->status() === 401 || $reponse->status() === 403) {
            return ['publie' => false, 'recus' => 0, 'connus' => 0, 'message' => 'Jeton de réseau refusé par le point de rendez-vous.'];
        }

        if (! $reponse->successful()) {
            return ['publie' => false, 'recus' => 0, 'connus' => 0, 'message' => 'Le point de rendez-vous a répondu '.$reponse->status().'.'];
        }

        $recus = $this->enregistrer((array) ($reponse->json('bulletins') ?? []), $maison->code);
        $this->purger();

        // Ce qui compte à l'écran n'est pas le nombre de lignes réécrites —
        // il est nul quand ce serveur tient lui-même le point de rendez-vous —
        // mais le nombre d'hôpitaux dont on connaît le stock à jour.
        $connus = count($this->codesAnnonces());

        return [
            'publie' => $partage,
            'recus' => $recus,
            'connus' => $connus,
            'message' => ($partage ? 'Stock publié' : 'Stock non publié (retiré du réseau)')
                .' ; '.($connus === 0
                    ? 'aucun autre hôpital n\'annonce de stock pour l\'instant.'
                    : $connus.' hôpital(aux) au réseau, stock à jour.'),
        ];
    }

    /**
     * Ranger les bulletins reçus. Un code déjà connu écrase le précédent :
     * seul le dernier état compte.
     *
     * @param  array<int, array<string, mixed>>  $bulletins
     */
    public function enregistrer(array $bulletins, ?string $codeLocal = null): int
    {
        $recus = 0;

        foreach ($bulletins as $bulletin) {
            $code = (string) ($bulletin['etablissement_code'] ?? '');

            // Le nôtre nous revient parfois par ricochet : il n'a rien à
            // faire dans la liste des autres.
            if ($code === '' || $code === $codeLocal) {
                continue;
            }

            $publieLe = $this->heure($bulletin['publie_le'] ?? null);

            // Un bulletin plus vieux que celui qu'on a déjà ne doit pas
            // écraser le plus récent : les liaisons se doublent.
            $connu = BulletinStockSang::where('etablissement_code', $code)->first();

            if ($connu && $connu->publie_le->gte($publieLe)) {
                continue;
            }

            BulletinStockSang::updateOrCreate(
                ['etablissement_code' => $code],
                [
                    'nom' => (string) ($bulletin['nom'] ?? $code),
                    'ville' => $bulletin['ville'] ?? null,
                    'province' => $bulletin['province'] ?? null,
                    'telephone' => $bulletin['telephone'] ?? null,
                    'stock' => $this->nombresSeulement($bulletin['stock'] ?? []),
                    'donneurs' => $this->entiers($bulletin['donneurs'] ?? []),
                    'publie_le' => $publieLe,
                    'recu_le' => now(),
                ]
            );

            $recus++;
        }

        return $recus;
    }

    /**
     * Ce que le point de rendez-vous renvoie à une maison : les bulletins
     * des autres, jamais le sien.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bulletinsPourAutrui(string $codeDemandeur): array
    {
        return BulletinStockSang::exploitables()
            ->where('etablissement_code', '!=', $codeDemandeur)
            ->get()
            ->map(fn (BulletinStockSang $b) => [
                'etablissement_code' => $b->etablissement_code,
                'nom' => $b->nom,
                'ville' => $b->ville,
                'province' => $b->province,
                'telephone' => $b->telephone,
                'stock' => $b->stock,
                'donneurs' => $b->donneurs,
                'publie_le' => $b->publie_le->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Ce que les maisons distantes annoncent, mis en forme comme le stock
     * local pour que l'écran n'ait qu'une seule liste à afficher.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function maisonsDistantes(?string $groupeReceveur, string $typeProduit): Collection
    {
        $groupesUtiles = $groupeReceveur
            ? PocheSang::groupesCompatiblesPour($groupeReceveur, $typeProduit)
            : PocheSang::GROUPES;

        // Notre propre bulletin nous revient quand ce serveur tient aussi le
        // point de rendez-vous. Sans cette exclusion, l'hôpital se verrait
        // lui-même parmi les hôpitaux distants — deux stocks pour une maison.
        return BulletinStockSang::exploitables()
            ->where('etablissement_code', '!=', (string) config('dpi.establishment_code'))
            ->orderBy('nom')
            ->get()
            ->map(fn (BulletinStockSang $b) => [
                'id' => 'bulletin-'.$b->etablissement_code,
                'nom' => $b->nom,
                'ville' => $b->ville,
                'telephone' => $b->telephone,
                'total' => $b->total(),
                'compatibles' => $b->nombrePour($groupesUtiles, $typeProduit),
                'par_groupe' => collect($b->parGroupe($typeProduit)),
                'donneurs' => (int) collect($b->donneurs ?? [])->sum(),
                'donneurs_compatibles' => $b->donneursPour($groupesUtiles),
                // Les donneurs d'une maison distante ne se rappellent pas
                // d'ici : on appelle la maison, elle appelle les siens.
                'a_appeler' => collect(),
                'distant' => true,
                'age' => $b->libelleAge(),
                'frais' => $b->estFrais(),
            ]);
    }

    /**
     * Les codes pour lesquels un bulletin fait autorité.
     *
     * Le serveur qui tient le point de rendez-vous doit connaître les autres
     * hôpitaux — c'est ainsi qu'il vérifie leur jeton. Ces fiches n'ont
     * pourtant aucune poche dans sa base : les afficher en direct montrerait
     * « 0 poche » pour un hôpital qui en a quinze. Là où un bulletin existe,
     * c'est lui qui dit la vérité.
     *
     * @return array<int, string>
     */
    public function codesAnnonces(): array
    {
        return BulletinStockSang::exploitables()
            ->where('etablissement_code', '!=', (string) config('dpi.establishment_code'))
            ->pluck('etablissement_code')
            ->all();
    }

    /** Un bulletin trop vieux ne rend plus service : il induit en erreur. */
    public function purger(): int
    {
        return BulletinStockSang::where('publie_le', '<', now()->subHours(BulletinStockSang::PERIME_HEURES))->delete();
    }

    private function heure(mixed $valeur): Carbon
    {
        try {
            $heure = $valeur ? Carbon::parse((string) $valeur) : now();
        } catch (\Throwable) {
            return now();
        }

        // Une horloge mal réglée en face ne doit pas produire un bulletin
        // « publié dans deux heures », éternellement frais.
        return $heure->isFuture() ? now() : $heure;
    }

    /**
     * Ne garder que des nombres : ce qui arrive du réseau est une déclaration,
     * pas une vérité, et n'a pas à traverser l'application telle quelle.
     */
    private function nombresSeulement(mixed $stock): array
    {
        return collect(is_array($stock) ? $stock : [])
            ->only(array_keys(PocheSang::PRODUITS))
            ->map(fn ($parGroupe) => $this->entiers($parGroupe))
            ->filter()
            ->all();
    }

    private function entiers(mixed $parGroupe): array
    {
        return collect(is_array($parGroupe) ? $parGroupe : [])
            ->only(PocheSang::GROUPES)
            ->map(fn ($n) => max(0, (int) $n))
            ->filter()
            ->all();
    }
}
