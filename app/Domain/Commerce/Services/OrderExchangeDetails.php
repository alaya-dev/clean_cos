<?php

namespace App\Domain\Commerce\Services;

use Illuminate\Validation\ValidationException;

class OrderExchangeDetails
{
    /**
     * @param  array<string, mixed>  $customer
     * @return array{is_exchange: bool, exchange_article_designation: string|null, exchange_article_count: int|null}
     */
    public function fromCheckoutCustomer(array $customer): array
    {
        $choice = $this->firstValue($customer, ['is_exchange', 'exchange', 'echange']);
        $designation = $this->firstValue($customer, ['exchange_article_designation', 'article_designation', 'article']);
        $count = $this->firstValue($customer, ['exchange_article_count', 'article_count', 'nb_echange']);

        return $this->normalize($choice === null && $designation === null && $count === null
            ? null
            : ['is_exchange' => $choice ?? false, 'article_designation' => $designation, 'article_count' => $count]);
    }

    /**
     * @param  array<string, mixed>|null  $exchange
     * @return array{is_exchange: bool, exchange_article_designation: string|null, exchange_article_count: int|null}
     */
    public function normalize(?array $exchange): array
    {
        $exchange ??= [];
        $rawChoice = $exchange['is_exchange'] ?? false;
        $isExchange = match ($rawChoice) {
            'Oui' => true,
            'Non' => false,
            default => filter_var($rawChoice, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        };
        if ($isExchange === null) {
            throw ValidationException::withMessages(['exchange.is_exchange' => 'Le choix d’échange est invalide.']);
        }

        if (! $isExchange) {
            return [
                'is_exchange' => false,
                'exchange_article_designation' => null,
                'exchange_article_count' => null,
            ];
        }

        $designation = is_string($exchange['article_designation'] ?? null)
            ? trim($exchange['article_designation'])
            : '';
        $count = filter_var($exchange['article_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $errors = [];
        if ($designation === '') {
            $errors['exchange.article_designation'] = 'La désignation des articles à échanger est requise.';
        } elseif (mb_strlen($designation) > 500) {
            $errors['exchange.article_designation'] = 'La désignation des articles à échanger ne peut pas dépasser 500 caractères.';
        }
        if ($count === false) {
            $errors['exchange.article_count'] = 'Le nombre d’articles à échanger doit être un entier supérieur ou égal à 1.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'is_exchange' => true,
            'exchange_article_designation' => $designation,
            'exchange_article_count' => (int) $count,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $keys
     */
    private function firstValue(array $values, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        }

        return null;
    }
}
