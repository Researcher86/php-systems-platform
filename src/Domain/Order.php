<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * An immutable order as the API sees it.
 *
 * `amount` is deliberately a string: the database stores DECIMAL(10,2) as a
 * fixed-scale string and money that travels through a JSON round trip should
 * not first become a float. The API accepts numbers and normalizes them to
 * "12.34" before anything is persisted.
 */
final readonly class Order
{
    public function __construct(
        public string $id,
        public string $customer,
        public string $amount,
        public OrderStatus $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * The JSON representation, one key per storage column and the status as
     * its lowercase wire value.
     *
     * @return array{
     *     id: string,
     *     customer: string,
     *     amount: string,
     *     status: string,
     *     created_at: string,
     *     updated_at: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->customer,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
