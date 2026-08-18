<?php

namespace App\Domain\Navex\Enums;

use Illuminate\Support\Str;

enum NavexDeliveryStatus: string
{
    case NotSent = 'non_envoyee';
    case PendingSend = 'en_attente_envoi';
    case Sending = 'envoi_en_cours';
    case UncertainResult = 'resultat_incertain';
    case Accepted = 'acceptee_navex';
    case Pending = 'en_attente_navex';
    case InDelivery = 'en_cours_livraison';
    case DeliveredPaid = 'livree_payee';
    case Returned = 'retournee';
    case CancellationPending = 'annulation_en_attente';
    case Cancelled = 'annulee_navex';
    case SynchronizationError = 'erreur_synchronisation';
    case ManualActionRequired = 'action_manuelle_requise';

    public static function fromProviderStatus(?string $rawStatus): ?self
    {
        $normalized = mb_strtolower(trim(Str::ascii((string) $rawStatus)));

        return match ($normalized) {
            'livrer paye' => self::DeliveredPaid,
            'retourne' => self::Returned,
            'supprime' => self::Cancelled,
            'en attente' => self::Pending,
            'en cours' => self::InDelivery,
            default => null,
        };
    }

    public function representsProviderState(): bool
    {
        return in_array($this, [
            self::Accepted,
            self::Pending,
            self::InDelivery,
            self::DeliveredPaid,
            self::Returned,
            self::CancellationPending,
            self::Cancelled,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Non envoyée',
            self::PendingSend => 'En attente d’envoi',
            self::Sending => 'Envoi en cours',
            self::UncertainResult => 'Résultat incertain',
            self::Accepted => 'En attente chez Navex',
            self::Pending => 'En attente chez Navex',
            self::InDelivery => 'En cours de livraison',
            self::DeliveredPaid => 'Livrée et payée',
            self::Returned => 'Retournée',
            self::CancellationPending => 'Annulation en attente',
            self::Cancelled => 'Annulée chez Navex',
            self::SynchronizationError => 'Erreur de synchronisation',
            self::ManualActionRequired => 'Action manuelle requise',
        };
    }

    public function terminal(): bool
    {
        return in_array($this, [self::Cancelled, self::DeliveredPaid, self::Returned], true);
    }
}
