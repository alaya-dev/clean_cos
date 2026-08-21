<?php

namespace App\Domain\FirstDelivery\Enums;

enum FirstDeliveryPickupStatus: string
{
    case Pending = 'pending';
    case Creating = 'creating';
    case Created = 'created';
    case UncertainResult = 'uncertain_result';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de création',
            self::Creating => 'Création en cours',
            self::Created => 'Manifeste créé',
            self::UncertainResult => 'Résultat incertain',
            self::Failed => 'Échec de création',
        };
    }
}
