<?php

namespace App\Services\Laboratoire;

use App\Models\JetonLaboratoire;
use App\Models\Partenaire;
use App\Models\User;

/**
 * Qui agit au nom d'un laboratoire : un compte du portail, ou le logiciel du
 * laboratoire par son jeton d'API. Les deux passent par les mêmes règles.
 */
final class ActeurLaboratoire
{
    private function __construct(
        public readonly Partenaire $laboratoire,
        public readonly ?User $compte,
        public readonly ?JetonLaboratoire $jeton,
    ) {}

    public static function compte(User $compte, Partenaire $laboratoire): self
    {
        return new self($laboratoire, $compte, null);
    }

    public static function jeton(JetonLaboratoire $jeton): self
    {
        return new self($jeton->laboratoire, null, $jeton);
    }

    public function source(): string
    {
        return $this->jeton ? 'api' : 'portail';
    }

    /** Clé de limite de débit propre au laboratoire. */
    public function cle(): string
    {
        return 'laboratoire:'.$this->laboratoire->id;
    }
}
