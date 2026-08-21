<?php

namespace App\Domain\FirstDelivery\Enums;

use Illuminate\Support\Str;

enum FirstDeliveryStatus: string
{
    case NotSent = 'non_envoyee';
    case PendingSend = 'en_attente_envoi';
    case Sending = 'envoi_en_cours';
    case UncertainResult = 'resultat_incertain';
    case Accepted = 'acceptee_first_delivery';
    case Pending = 'en_attente_first_delivery';
    case InProgress = 'en_cours_first_delivery';
    case Delivered = 'livree_first_delivery';
    case Exchange = 'echange_first_delivery';
    case ReturnedToSender = 'retour_expediteur';
    case Cancelled = 'annulee_first_delivery';
    case ReturnClientAgency = 'retour_client_agence';
    case AtStore = 'au_magasin';
    case ReturnWarehouse = 'retour_depot';
    case Verify = 'a_verifier';
    case ReturnReceived = 'retour_recu';
    case FinalReturn = 'retour_definitif';
    case PickupRequested = 'enlevement_demande';
    case PickupAssigned = 'enlevement_assigne';
    case PickupInProgress = 'enlevement_en_cours';
    case PickedUp = 'enleve';
    case PickupCancelled = 'enlevement_annule';
    case ReturnAssigned = 'retour_assigne';
    case ReturnInTransit = 'retour_en_transit';
    case ReturnPickedUp = 'retour_enleve';
    case ReturnCancelled = 'retour_annule';
    case CancellationPending = 'annulation_en_attente';
    case SynchronizationError = 'erreur_synchronisation';
    case ManualActionRequired = 'action_manuelle_requise';

    public static function fromProviderState(mixed $state): ?self
    {
        return match (self::providerCode($state)) {
            0 => self::Pending,
            1 => self::InProgress,
            2 => self::Delivered,
            3 => self::Exchange,
            5 => self::ReturnedToSender,
            6 => self::Cancelled,
            7 => self::ReturnClientAgency,
            8 => self::AtStore,
            11 => self::ReturnWarehouse,
            20 => self::Verify,
            30 => self::ReturnReceived,
            31 => self::FinalReturn,
            100 => self::PickupRequested,
            101 => self::PickupAssigned,
            102 => self::PickupInProgress,
            103 => self::PickedUp,
            104 => self::PickupCancelled,
            201 => self::ReturnAssigned,
            202 => self::ReturnInTransit,
            203 => self::ReturnPickedUp,
            204 => self::ReturnCancelled,
            default => null,
        };
    }

    public static function providerCode(mixed $state): ?int
    {
        if (is_int($state) || (is_string($state) && preg_match('/^\d+$/D', trim($state)) === 1)) {
            return (int) $state;
        }
        if (! is_string($state)) {
            return null;
        }

        return match (mb_strtolower(trim(Str::ascii($state)))) {
            'en attente' => 0,
            'en cours' => 1,
            'livre' => 2,
            'echange' => 3,
            'retour expediteur' => 5,
            'supprime' => 6,
            'rtn client/agence' => 7,
            'au magasin' => 8,
            'rtn depot' => 11,
            'a verifier' => 20,
            'retour recu' => 30,
            'rtn definitif', 'retour definitif' => 31,
            'demande d\'enlevement' => 100,
            'demande d\'enlevement assignee' => 101,
            'en cours d\'enlevement' => 102,
            'enleve' => 103,
            'demande d\'enlevement annule' => 104,
            'retour assigne' => 201,
            'retour en cours d\'expedition' => 202,
            'retour enleve' => 203,
            'retour annule' => 204,
            default => null,
        };
    }

    public function representsProviderState(): bool
    {
        return ! in_array($this, [
            self::NotSent,
            self::PendingSend,
            self::Sending,
            self::UncertainResult,
            self::CancellationPending,
            self::SynchronizationError,
            self::ManualActionRequired,
        ], true);
    }

    public function terminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::FinalReturn], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Non envoyée',
            self::PendingSend => 'En attente d’envoi',
            self::Sending => 'Envoi en cours',
            self::UncertainResult => 'Résultat incertain',
            self::Accepted => 'Acceptée par First Delivery',
            self::Pending => 'En attente chez First Delivery',
            self::InProgress => 'En cours de livraison',
            self::Delivered => 'Livrée',
            self::Exchange => 'Échange',
            self::ReturnedToSender => 'Retour expéditeur',
            self::Cancelled => 'Annulée chez First Delivery',
            self::ReturnClientAgency => 'Retour client/agence',
            self::AtStore => 'Au magasin',
            self::ReturnWarehouse => 'Retour dépôt',
            self::Verify => 'À vérifier',
            self::ReturnReceived => 'Retour reçu',
            self::FinalReturn => 'Retour définitif',
            self::PickupRequested => 'Demande d’enlèvement',
            self::PickupAssigned => 'Enlèvement assigné',
            self::PickupInProgress => 'Enlèvement en cours',
            self::PickedUp => 'Enlevé',
            self::PickupCancelled => 'Demande d’enlèvement annulée',
            self::ReturnAssigned => 'Retour assigné',
            self::ReturnInTransit => 'Retour en cours d’expédition',
            self::ReturnPickedUp => 'Retour enlevé',
            self::ReturnCancelled => 'Retour annulé',
            self::CancellationPending => 'Annulation en attente',
            self::SynchronizationError => 'Erreur de synchronisation',
            self::ManualActionRequired => 'Action manuelle requise',
        };
    }
}
