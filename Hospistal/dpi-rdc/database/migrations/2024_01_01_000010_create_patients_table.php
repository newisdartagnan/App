<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('dossier_number', 50)->unique();
            $table->uuid('global_patient_id')->nullable()->index();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('nom_soundex', 20)->nullable();
            $table->string('prenom_soundex', 20)->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('lieu_naissance', 150)->nullable();
            $table->enum('sexe', ['M', 'F', 'Inconnu'])->default('Inconnu');
            $table->string('nationalite', 100)->default('Congolaise');
            $table->text('telephone')->nullable();
            $table->text('adresse')->nullable();
            $table->string('province', 100)->nullable();
            $table->string('territoire', 100)->nullable();
            $table->string('profession', 100)->nullable();
            $table->enum('situation_matrimoniale', ['celibataire', 'marie', 'divorce', 'veuf', 'inconnu'])->default('inconnu');
            $table->enum('niveau_instruction', ['aucun', 'primaire', 'secondaire', 'superieur', 'inconnu'])->default('inconnu');
            $table->string('contact_urgence_nom', 200)->nullable();
            $table->text('contact_urgence_telephone')->nullable();
            $table->string('contact_urgence_lien', 100)->nullable();
            $table->enum('type_prise_en_charge', ['prive', 'assurance', 'indigent', 'fonctionnaire', 'autre'])->default('prive');
            $table->string('assurance_nom', 100)->nullable();
            $table->string('assurance_numero', 100)->nullable();
            $table->uuid('duplicate_of')->nullable();
            $table->decimal('duplicate_confidence', 5, 2)->nullable();
            $table->enum('merge_status', ['original', 'merged', 'suspect'])->default('original');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->string('sync_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('ALTER TABLE patients ADD CONSTRAINT patients_duplicate_of_foreign FOREIGN KEY (duplicate_of) REFERENCES patients (id) ON DELETE SET NULL');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX idx_patients_dedup ON patients USING gin(to_tsvector('french', nom || ' ' || prenom))");
            DB::statement('CREATE INDEX idx_patients_soundex ON patients (nom_soundex, prenom_soundex)');
            DB::statement('CREATE INDEX idx_patients_dob ON patients (date_naissance, sexe)');
            DB::statement('CREATE INDEX idx_patients_nom_trgm ON patients USING gin (nom gin_trgm_ops)');
            DB::statement('CREATE INDEX idx_patients_prenom_trgm ON patients USING gin (prenom gin_trgm_ops)');
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};