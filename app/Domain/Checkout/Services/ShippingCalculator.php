<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Settings\Services\StoreSettings;

class ShippingCalculator
{
    public function __construct(private readonly StoreSettings $settings) {}

    /** @return array{is_free: bool, fee: array{millimes: int, formatted: string}, free_threshold: array{millimes: int, formatted: string}|null} */
    public function calculate(int $subtotalMillimes): array
    {
        $thresholdEnabled = (bool) $this->settings->get('shipping.free_threshold_enabled');
        $threshold = $this->settings->get('shipping.free_threshold_millimes');
        $fixedFee = (int) $this->settings->get('shipping.fixed_fee_millimes');
        $isFree = $fixedFee === 0 || ($thresholdEnabled && is_int($threshold) && $subtotalMillimes >= $threshold);
        $fee = $isFree ? 0 : $fixedFee;

        return [
            'is_free' => $isFree,
            'fee' => $this->money($fee, $fee === 0),
            'free_threshold' => $thresholdEnabled && is_int($threshold) ? $this->money($threshold) : null,
        ];
    }

    /** @return array{millimes: int, formatted: string} */
    public function money(int $millimes, bool $free = false): array
    {
        return [
            'millimes' => $millimes,
            'formatted' => $free ? 'Gratuite' : number_format($millimes / 1000, 3, ',', ' ').' TND',
        ];
    }

    public function announcement(): string
    {
        $thresholdEnabled = (bool) $this->settings->get('shipping.free_threshold_enabled');
        $threshold = $this->settings->get('shipping.free_threshold_millimes');
        if ($thresholdEnabled && is_int($threshold)) {
            return 'Livraison offerte dès '.$this->money($threshold)['formatted'];
        }

        return 'Les frais de livraison sont calculés à la commande.';
    }
}
