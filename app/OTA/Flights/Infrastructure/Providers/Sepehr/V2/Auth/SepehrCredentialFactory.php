<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Auth;

class SepehrCredentialFactory
{
    public function fromRow(object $row): SepehrCredential
    {
        $data       = $row->data ? json_decode($row->data, true) : [];
        $hasNira    = (bool) ($data['nira'] ?? false);
        $isHub      = ($row->branch == 1);

        return new SepehrCredential(
            id:                 $row->id,
            supplierObjectId:   $row->object,
            branch:             $row->branch,
            url:                $row->url,
            username:           $row->username,
            hashedPassword:     md5($row->password),
            hasNira:            $hasNira,
            isHub:              $isHub,
        );
    }

    /** @return SepehrCredential[] */
    public function fromRows(iterable $rows): array
    {
        return array_map(fn($row) => $this->fromRow($row), [...$rows]);
    }
}