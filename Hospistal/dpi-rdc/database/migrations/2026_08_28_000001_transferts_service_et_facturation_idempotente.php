<?php

use Database\Seeders\DisponibiliteMedecinSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // FACTURATION IDEMPOTENTE
        //
        // Les journées d'hospitalisation étaient recalculées à chaque
        // émission : facturer deux fois le même séjour le facturait deux
        // fois. On mémorise ce qui a déjà été porté sur une facture, de
        // sorte qu'une nouvelle émission ne réclame que les journées
        // écoulées depuis la précédente.
        // ══════════════════════════════════════════════════════════════
        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedSmallInteger('jours_factures')->default(0)->after('duree_sejour_jours');
        });

        // Reprise : les séjours déjà facturés portent les journées de leur
        // dernière facture d'hospitalisation.
        DB::statement("
            UPDATE visits v
            SET jours_factures = COALESCE(sub.jours, 0)
            FROM (
                SELECT f.visit_id, MAX(lf.quantite) AS jours
                FROM lignes_facture lf
                JOIN factures f ON f.id = lf.facture_id
                WHERE lf.type = 'hospitalisation'
                GROUP BY f.visit_id
            ) sub
            WHERE sub.visit_id = v.id
        ");

        // ══════════════════════════════════════════════════════════════
        // TRANSFERTS INTER-SERVICES
        //
        // Un patient qui passe de la réanimation à la médecine interne ne
        // sort pas : c'est le même séjour, la même admission, le même
        // dossier. Seuls le service et le lit changent, et l'on garde
        // trace de qui a demandé le transfert et pourquoi.
        // ══════════════════════════════════════════════════════════════
        Schema::create('transferts_service', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('service_source_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUuid('lit_source_id')->nullable()->constrained('lits')->nullOnDelete();
            $table->foreignUuid('service_destination_id')->constrained('services')->cascadeOnDelete();
            $table->foreignUuid('lit_destination_id')->nullable()->constrained('lits')->nullOnDelete();
            // Qui demande : un médecin de l'application, ou un nom libre
            // quand la demande vient d'un praticien sans compte.
            $table->foreignUuid('demandeur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('demandeur_nom', 150);
            $table->text('motif');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('transfere_a');
            $table->timestamps();

            $table->index(['visit_id', 'transfere_a']);
        });

        // ══════════════════════════════════════════════════════════════
        // PLAGES DE PRÉSENCE — les médecins déjà enregistrés n'en avaient
        // aucune, et étaient donc réputés présents en permanence. On leur
        // installe les horaires habituels, que chaque établissement ajuste
        // ensuite depuis l'écran de disponibilité.
        // ══════════════════════════════════════════════════════════════
        $medecins = DB::table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'medecin')
            ->where('users.is_active', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('disponibilites_medecin')
                ->whereColumn('disponibilites_medecin.user_id', 'users.id'))
            ->pluck('users.id');

        foreach ($medecins as $medecinId) {
            foreach (DisponibiliteMedecinSeeder::PLAGES_PAR_DEFAUT as [$jour, $debut, $fin]) {
                DB::table('disponibilites_medecin')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $medecinId,
                    'jour_semaine' => $jour,
                    'heure_debut' => $debut,
                    'heure_fin' => $fin,
                    'lieu' => 'Consultation externe',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transferts_service');

        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('jours_factures');
        });
    }
};
