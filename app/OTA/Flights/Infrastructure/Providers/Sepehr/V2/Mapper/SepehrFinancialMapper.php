<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Mapper;

use App\Services\OTA\Flights\Domain\ValueObjects\FarePrice;
use App\Services\OTA\Flights\Domain\ValueObjects\Financial;
use App\Services\OTA\Flights\Domain\ValueObjects\RoundtripFare;

class SepehrFinancialMapper
{
    public function mapFare(array $fare, int $markup = 0): FarePrice
    {
        $totalFare  = (int) $fare['TotalFare'];
        $commission = (int) ($fare['Commision'] ?? $fare['Commission'] ?? 0);

        return new FarePrice(
            baseFare:             (int) $fare['BaseFare'],
            tax:                  (int) $fare['Tax'],
            markup:               $markup,
            totalFare:            $totalFare,
            payable:              $markup > 0
                                    ? (int) ($totalFare + ($markup / 100) * $totalFare)
                                    : (int) ($fare['Payable'] ?? $totalFare),
            commissionPercentage: $totalFare > 0 ? ($commission / $totalFare) * 100 : 0.0,
            commissionFinal:      $commission,
            commissionPrice:      $commission,
        );
    }

    public function mapFinancial(
        array $adultFare,
        array $childFare,
        array $infantFare,
        array|false $roundtripData = false,
        int $markupAdl = 0,
        int $markupChi = 0,
        int $markupInf = 0,
    ): Financial {
        return new Financial(
            citizenPriceAddition: 0,
            commissionPercentage: false,
            transaction:          0,
            membershipRight:      false,
            adult:                $this->mapFare($adultFare, $markupAdl),
            child:                $this->mapFare($childFare, $markupChi),
            infant:               $this->mapFare($infantFare, $markupInf),
            roundtripFare:        $roundtripData ? $this->mapRoundtripFare($roundtripData) : false,
        );
    }

    private function mapRoundtripFare(array $data): RoundtripFare
    {
        return new RoundtripFare(
            fromOriginAdult:       $this->mapFare($data['RoundtripFare_FromOrigin']['Adult_Fare']),
            fromOriginChild:       $this->mapFare($data['RoundtripFare_FromOrigin']['Child_Fare']),
            fromOriginInfant:      $this->mapFare($data['RoundtripFare_FromOrigin']['Infant_Fare']),
            fromDestinationAdult:  $this->mapFare($data['RoundtripFare_FromDestination']['Adult_Fare']),
            fromDestinationChild:  $this->mapFare($data['RoundtripFare_FromDestination']['Child_Fare']),
            fromDestinationInfant: $this->mapFare($data['RoundtripFare_FromDestination']['Infant_Fare']),
        );
    }

    /**
     * Financial خام (بدون markup) برای BaseData
     */
    public function mapBaseFinancial(array $adultFare, array $childFare, array $infantFare): Financial
    {
        return $this->mapFinancial($adultFare, $childFare, $infantFare);
    }
}