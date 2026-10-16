<?php

namespace App\Services\Commodity;

interface CommodityPriceProviderInterface
{
    /**
     * Ambil data harga komoditas pasar harian.
     *
     * @param array $options Filter lokasi (provinsi/kabupaten) atau tanggal
     * @return array Array terstruktur berisi data komoditas
     */
    public function fetchPrices(array $options = []): array;

    /**
     * Nama sumber provider (e.g. 'BAPANAS', 'PIHPS')
     */
    public function getProviderName(): string;
}
