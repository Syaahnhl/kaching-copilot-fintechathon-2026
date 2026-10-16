<?php

namespace App\Services\Financial;

use Illuminate\Support\Facades\DB;

/**
 * UnitOfMeasureService
 *
 * Deterministic, read-only unit of measure normalization, dimensional validation,
 * and item-specific packaging conversion engine for Kaching Finance Copilot.
 *
 * Strictly adheres to Phase 3.9B invariants:
 * 1. Zero data mutations (no insert, update, delete).
 * 2. Canonical normalization (case-insensitive, whitespace-tolerant).
 * 3. Strict dimensional firewall (no cross-dimension conversions without domain link).
 * 4. Item-specific item_uoms lookup with tenant/item scoping.
 * 5. Fail-closed contract: never guess a conversion.
 */
class UnitOfMeasureService
{
    // Physical Dimensions
    public const DIMENSION_MASS    = 'MASS';
    public const DIMENSION_VOLUME  = 'VOLUME';
    public const DIMENSION_COUNT   = 'COUNT';
    public const DIMENSION_PORTION = 'PORTION';
    public const DIMENSION_CUSTOM  = 'CUSTOM';

    // Required Contract Statuses (Phase 3.9D)
    public const STATUS_SUPPORTED               = 'SUPPORTED';
    public const STATUS_NORMALIZED              = 'NORMALIZED';
    public const STATUS_UNSUPPORTED_DATA        = 'UNSUPPORTED_DATA';
    public const STATUS_UNIT_DIMENSION_MISMATCH = 'UNIT_DIMENSION_MISMATCH';
    public const STATUS_CONVERSION_UNAVAILABLE  = 'UNIT_CONVERSION_UNAVAILABLE';
    public const STATUS_MISSING_METADATA        = 'MISSING_UNIT_METADATA';

    // Failure / Rejection Reasons
    public const REASON_UNIT_CONVERSION_UNAVAILABLE = 'UNIT_CONVERSION_UNAVAILABLE';
    public const REASON_UNIT_DIMENSION_MISMATCH     = 'UNIT_DIMENSION_MISMATCH';
    public const REASON_UNKNOWN_UNIT                = 'UNKNOWN_UNIT';
    public const REASON_MISSING_ITEM_CONVERSION     = 'MISSING_ITEM_CONVERSION';
    public const REASON_INVALID_CONVERSION_FACTOR   = 'INVALID_CONVERSION_FACTOR';
    public const REASON_MISSING_UNIT_METADATA       = 'MISSING_UNIT_METADATA';

    /**
     * Canonical alias mapping for standard units.
     * Normalized string => ['canonical' => string, 'dimension' => string, 'factor_to_base' => float]
     */
    protected array $canonicalMap = [
        // Mass (base: g)
        'g'          => ['canonical' => 'g', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1.0],
        'gr'         => ['canonical' => 'g', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1.0],
        'gram'       => ['canonical' => 'g', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1.0],
        'grams'      => ['canonical' => 'g', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1.0],
        'gramm'      => ['canonical' => 'g', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1.0],
        'kg'         => ['canonical' => 'kg', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1000.0],
        'kilo'       => ['canonical' => 'kg', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1000.0],
        'kilogram'   => ['canonical' => 'kg', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1000.0],
        'kilograms'  => ['canonical' => 'kg', 'dimension' => self::DIMENSION_MASS, 'base_factor' => 1000.0],

        // Volume (base: ml)
        'ml'         => ['canonical' => 'ml', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1.0],
        'mili'       => ['canonical' => 'ml', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1.0],
        'mililiter'  => ['canonical' => 'ml', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1.0],
        'milliliter' => ['canonical' => 'ml', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1.0],
        'milliliters'=> ['canonical' => 'ml', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1.0],
        'l'          => ['canonical' => 'l', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1000.0],
        'liter'      => ['canonical' => 'l', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1000.0],
        'liters'     => ['canonical' => 'l', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1000.0],
        'ltr'        => ['canonical' => 'l', 'dimension' => self::DIMENSION_VOLUME, 'base_factor' => 1000.0],

        // Count (base: pcs / butir isolated)
        'pcs'        => ['canonical' => 'pcs', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],
        'pc'         => ['canonical' => 'pcs', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],
        'piece'      => ['canonical' => 'pcs', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],
        'pieces'     => ['canonical' => 'pcs', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],
        'biji'       => ['canonical' => 'pcs', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],
        'unit'       => ['canonical' => 'pcs', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],
        'lembar'     => ['canonical' => 'pcs', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],

        // Butir: preserved as its own count unit per Phase A evidence
        'butir'      => ['canonical' => 'butir', 'dimension' => self::DIMENSION_COUNT, 'base_factor' => 1.0],

        // Portion (base: porsi - Discrete F&B demand)
        'porsi'      => ['canonical' => 'porsi', 'dimension' => self::DIMENSION_PORTION, 'base_factor' => 1.0],
        'portion'    => ['canonical' => 'porsi', 'dimension' => self::DIMENSION_PORTION, 'base_factor' => 1.0],
        'portions'   => ['canonical' => 'porsi', 'dimension' => self::DIMENSION_PORTION, 'base_factor' => 1.0],
        'serving'    => ['canonical' => 'porsi', 'dimension' => self::DIMENSION_PORTION, 'base_factor' => 1.0],
        'servings'   => ['canonical' => 'porsi', 'dimension' => self::DIMENSION_PORTION, 'base_factor' => 1.0],
        'cup'        => ['canonical' => 'porsi', 'dimension' => self::DIMENSION_PORTION, 'base_factor' => 1.0],
        'porsii'     => ['canonical' => 'porsi', 'dimension' => self::DIMENSION_PORTION, 'base_factor' => 1.0],
    ];

    /**
     * Normalize a unit string into its canonical representation.
     */
    public function normalizeUnit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $clean = trim(mb_strtolower($unit, 'UTF-8'));
        $clean = rtrim($clean, '.');

        if ($clean === '') {
            return null;
        }

        if (isset($this->canonicalMap[$clean])) {
            return $this->canonicalMap[$clean]['canonical'];
        }

        return $clean;
    }

    /**
     * Get the physical dimension of a canonical unit.
     */
    public function getDimension(?string $canonicalUnit): string
    {
        if ($canonicalUnit === null) {
            return self::DIMENSION_CUSTOM;
        }

        $clean = trim(mb_strtolower($canonicalUnit, 'UTF-8'));
        $clean = rtrim($clean, '.');

        if (isset($this->canonicalMap[$clean])) {
            return $this->canonicalMap[$clean]['dimension'];
        }

        return self::DIMENSION_CUSTOM;
    }

    /**
     * Check if a unit is a recognized standard unit.
     */
    public function isStandardUnit(?string $unit): bool
    {
        if ($unit === null) {
            return false;
        }

        $clean = trim(mb_strtolower($unit, 'UTF-8'));
        $clean = rtrim($clean, '.');

        return isset($this->canonicalMap[$clean]);
    }

    /**
     * Convert quantity from one unit to another.
     *
     * @param float       $quantity
     * @param string|null $fromUnit
     * @param string|null $toUnit
     * @param int|null    $itemId
     * @param int|null    $outletId Optional outlet isolation verification
     * @return array
     */
    public function convert(
        float $quantity,
        ?string $fromUnit,
        ?string $toUnit,
        ?int $itemId = null,
        ?int $outletId = null
    ): array {
        $rawFrom = $fromUnit;
        $rawTo = $toUnit;

        $canonFrom = $this->normalizeUnit($fromUnit);
        $canonTo = $this->normalizeUnit($toUnit);

        // 1. Identical units (including case & alias insensitivity: Gram == gr, Pcs == pcs)
        if ($canonFrom !== null && $canonTo !== null && $canonFrom === $canonTo) {
            return [
                'success'        => true,
                'converted_qty'  => $quantity,
                'from_unit'      => $fromUnit,
                'to_unit'        => $toUnit,
                'canonical_from' => $canonFrom,
                'canonical_to'   => $canonTo,
                'factor'         => 1.0,
                'status'         => 'SUCCESS',
                'reason'         => null,
            ];
        }

        // If either unit is null/empty and they weren't matched
        if ($canonFrom === null || $canonTo === null) {
            return [
                'success'        => false,
                'converted_qty'  => null,
                'from_unit'      => $fromUnit,
                'to_unit'        => $toUnit,
                'canonical_from' => $canonFrom,
                'canonical_to'   => $canonTo,
                'factor'         => null,
                'status'         => StockoutIntelligenceService::STATUS_UNSUPPORTED,
                'reason'         => self::REASON_UNKNOWN_UNIT,
            ];
        }

        $dimFrom = $this->getDimension($canonFrom);
        $dimTo = $this->getDimension($canonTo);

        // 2. Standard Metric SI Conversions within the exact same physical dimension
        if ($dimFrom === $dimTo && $dimFrom !== self::DIMENSION_CUSTOM) {
            // Mass: kg <-> g
            if ($dimFrom === self::DIMENSION_MASS) {
                if ($canonFrom === 'kg' && $canonTo === 'g') {
                    return [
                        'success'        => true,
                        'converted_qty'  => round($quantity * 1000.0, 6),
                        'from_unit'      => $fromUnit,
                        'to_unit'        => $toUnit,
                        'canonical_from' => $canonFrom,
                        'canonical_to'   => $canonTo,
                        'factor'         => 1000.0,
                        'status'         => 'SUCCESS',
                        'reason'         => null,
                    ];
                }
                if ($canonFrom === 'g' && $canonTo === 'kg') {
                    return [
                        'success'        => true,
                        'converted_qty'  => round($quantity / 1000.0, 6),
                        'from_unit'      => $fromUnit,
                        'to_unit'        => $toUnit,
                        'canonical_from' => $canonFrom,
                        'canonical_to'   => $canonTo,
                        'factor'         => 0.001,
                        'status'         => 'SUCCESS',
                        'reason'         => null,
                    ];
                }
            }

            // Volume: l <-> ml
            if ($dimFrom === self::DIMENSION_VOLUME) {
                if ($canonFrom === 'l' && $canonTo === 'ml') {
                    return [
                        'success'        => true,
                        'converted_qty'  => round($quantity * 1000.0, 6),
                        'from_unit'      => $fromUnit,
                        'to_unit'        => $toUnit,
                        'canonical_from' => $canonFrom,
                        'canonical_to'   => $canonTo,
                        'factor'         => 1000.0,
                        'status'         => 'SUCCESS',
                        'reason'         => null,
                    ];
                }
                if ($canonFrom === 'ml' && $canonTo === 'l') {
                    return [
                        'success'        => true,
                        'converted_qty'  => round($quantity / 1000.0, 6),
                        'from_unit'      => $fromUnit,
                        'to_unit'        => $toUnit,
                        'canonical_from' => $canonFrom,
                        'canonical_to'   => $canonTo,
                        'factor'         => 0.001,
                        'status'         => 'SUCCESS',
                        'reason'         => null,
                    ];
                }
            }

            // Count: pcs <-> butir is NOT allowed without explicit item conversion
            if ($dimFrom === self::DIMENSION_COUNT) {
                // If not identical (e.g. pcs vs butir), fall through to item_uoms or fail
            }
        }

        // 3. Item-Specific Packaging Conversion via existing item_uoms table
        if ($itemId !== null && $itemId > 0) {
            $itemUomResult = $this->resolveItemUomConversion($quantity, $rawFrom, $rawTo, $canonFrom, $canonTo, $itemId, $outletId);
            if ($itemUomResult !== null) {
                return $itemUomResult;
            }
        }

        // 4. Dimensional Safety Firewall: Reject cross-dimension conversions
        if ($dimFrom !== $dimTo) {
            if ($dimFrom !== self::DIMENSION_CUSTOM && $dimTo !== self::DIMENSION_CUSTOM) {
                return [
                    'success'        => false,
                    'converted_qty'  => null,
                    'from_unit'      => $fromUnit,
                    'to_unit'        => $toUnit,
                    'canonical_from' => $canonFrom,
                    'canonical_to'   => $canonTo,
                    'factor'         => null,
                    'status'         => StockoutIntelligenceService::STATUS_UNSUPPORTED,
                    'reason'         => self::REASON_UNIT_DIMENSION_MISMATCH,
                ];
            }

            // Custom unit (e.g. 'lingkaran' or item packaging name) without item-specific conversion
            return [
                'success'        => false,
                'converted_qty'  => null,
                'from_unit'      => $fromUnit,
                'to_unit'        => $toUnit,
                'canonical_from' => $canonFrom,
                'canonical_to'   => $canonTo,
                'factor'         => null,
                'status'         => StockoutIntelligenceService::STATUS_UNSUPPORTED,
                'reason'         => $itemId !== null ? self::REASON_MISSING_ITEM_CONVERSION : self::REASON_UNIT_CONVERSION_UNAVAILABLE,
            ];
        }

        // 5. Fail-Closed for missing conversion or custom units
        return [
            'success'        => false,
            'converted_qty'  => null,
            'from_unit'      => $fromUnit,
            'to_unit'        => $toUnit,
            'canonical_from' => $canonFrom,
            'canonical_to'   => $canonTo,
            'factor'         => null,
            'status'         => StockoutIntelligenceService::STATUS_UNSUPPORTED,
            'reason'         => $itemId !== null ? self::REASON_MISSING_ITEM_CONVERSION : self::REASON_UNIT_CONVERSION_UNAVAILABLE,
        ];
    }

    /**
     * Resolve packaging conversion from existing item_uoms table.
     * Strictly read-only, item-scoped, and optionally outlet-scoped.
     */
    protected function resolveItemUomConversion(
        float $quantity,
        ?string $rawFrom,
        ?string $rawTo,
        string $canonFrom,
        string $canonTo,
        int $itemId,
        ?int $outletId = null
    ): ?array {
        // Query item_uoms specifically for this item_id
        $query = DB::table('item_uoms as iu')
            ->join('items as i', 'iu.item_id', '=', 'i.id')
            ->where('iu.item_id', $itemId)
            ->whereNull('i.deleted_at');

        if ($outletId !== null && $outletId > 0) {
            $query->where('i.outlet_id', $outletId);
        }

        $records = $query->select('iu.id', 'iu.uom_name', 'iu.conversion_factor', 'i.base_uom')->get();

        if ($records->isEmpty()) {
            return null;
        }

        $cleanRawFrom = mb_strtolower(trim($rawFrom ?? ''), 'UTF-8');
        $cleanRawTo = mb_strtolower(trim($rawTo ?? ''), 'UTF-8');

        foreach ($records as $row) {
            $uomName = mb_strtolower(trim($row->uom_name), 'UTF-8');
            $factor = (float) $row->conversion_factor;
            $baseUomCanon = $this->normalizeUnit($row->base_uom);

            // Factor validation: must be strictly positive
            if ($factor <= 0.0) {
                return [
                    'success'        => false,
                    'converted_qty'  => null,
                    'from_unit'      => $rawFrom,
                    'to_unit'        => $rawTo,
                    'canonical_from' => $canonFrom,
                    'canonical_to'   => $canonTo,
                    'factor'         => null,
                    'status'         => StockoutIntelligenceService::STATUS_UNSUPPORTED,
                    'reason'         => self::REASON_INVALID_CONVERSION_FACTOR,
                ];
            }

            // Case A: From packaging UOM to item base_uom
            // e.g. from 'Kg' to 'g' (base_uom) where uom_name='Kg', factor=1000
            if (($cleanRawFrom === $uomName || $canonFrom === $this->normalizeUnit($uomName))
                && ($canonTo === $baseUomCanon || $cleanRawTo === mb_strtolower(trim($row->base_uom), 'UTF-8'))) {
                return [
                    'success'        => true,
                    'converted_qty'  => round($quantity * $factor, 6),
                    'from_unit'      => $rawFrom,
                    'to_unit'        => $rawTo,
                    'canonical_from' => $canonFrom,
                    'canonical_to'   => $canonTo,
                    'factor'         => $factor,
                    'status'         => 'SUCCESS',
                    'reason'         => null,
                ];
            }

            // Case B: From item base_uom to packaging UOM
            // e.g. from 'g' (base_uom) to 'Kg' where uom_name='Kg', factor=1000
            if (($canonFrom === $baseUomCanon || $cleanRawFrom === mb_strtolower(trim($row->base_uom), 'UTF-8'))
                && ($cleanRawTo === $uomName || $canonTo === $this->normalizeUnit($uomName))) {
                return [
                    'success'        => true,
                    'converted_qty'  => round($quantity / $factor, 6),
                    'from_unit'      => $rawFrom,
                    'to_unit'        => $rawTo,
                    'canonical_from' => $canonFrom,
                    'canonical_to'   => $canonTo,
                    'factor'         => round(1.0 / $factor, 8),
                    'status'         => 'SUCCESS',
                    'reason'         => null,
                ];
            }
        }

        return null;
    }

    /**
     * Resolve and validate a menu's unit of measure for discrete demand forecasting.
     * Strictly read-only, fail-closed, with zero guessing/inferences.
     *
     * @param int         $menuId
     * @param string|null $rawUnit
     * @param float       $currentPrice
     * @param int|null    $outletId
     * @param int|null    $itemId
     * @return array{
     *     status: string,
     *     canonical_unit: string|null,
     *     dimension: string|null,
     *     conversion_factor: float,
     *     is_discrete: bool,
     *     price_multiplier: float,
     *     failure_reason: string|null
     * }
     */
    public function resolveForecastUnit(
        int $menuId,
        ?string $rawUnit,
        float $currentPrice = 0.0,
        ?int $outletId = null,
        ?int $itemId = null
    ): array {
        if ($rawUnit === null || trim((string) $rawUnit) === '') {
            return [
                'status'            => self::STATUS_MISSING_METADATA,
                'canonical_unit'    => null,
                'dimension'         => null,
                'conversion_factor' => 1.0,
                'is_discrete'       => false,
                'price_multiplier'  => 1.0,
                'failure_reason'    => self::REASON_MISSING_UNIT_METADATA,
            ];
        }

        $clean = trim(mb_strtolower($rawUnit, 'UTF-8'));
        $clean = rtrim($clean, '.');

        $canonical = $this->normalizeUnit($rawUnit);
        $dimension = $this->getDimension($canonical);

        // 1. Portion & Count dimensions are supported discrete units
        if ($dimension === self::DIMENSION_PORTION) {
            return [
                'status'            => self::STATUS_SUPPORTED,
                'canonical_unit'    => $canonical,
                'dimension'         => self::DIMENSION_PORTION,
                'conversion_factor' => 1.0,
                'is_discrete'       => true,
                'price_multiplier'  => 1.0,
                'failure_reason'    => null,
            ];
        }

        if ($dimension === self::DIMENSION_COUNT) {
            return [
                'status'            => self::STATUS_SUPPORTED,
                'canonical_unit'    => $canonical,
                'dimension'         => self::DIMENSION_COUNT,
                'conversion_factor' => 1.0,
                'is_discrete'       => true,
                'price_multiplier'  => 1.0,
                'failure_reason'    => null,
            ];
        }

        // 2. Continuous dimensions (MASS, VOLUME) are rejected from the discrete engine
        if ($dimension === self::DIMENSION_MASS || $dimension === self::DIMENSION_VOLUME) {
            return [
                'status'            => self::STATUS_UNIT_DIMENSION_MISMATCH,
                'canonical_unit'    => $canonical,
                'dimension'         => $dimension,
                'conversion_factor' => 1.0,
                'is_discrete'       => false,
                'price_multiplier'  => 1.0,
                'failure_reason'    => self::REASON_UNIT_DIMENSION_MISMATCH,
            ];
        }

        // 3. Custom / Packaging units (Pack, Box, lingkaran, etc.)
        // Look up explicit mapping in item_uoms table
        if ($itemId === null && $menuId > 0) {
            try {
                $linkedItemId = DB::table('menu_ingredients')
                    ->where('menu_id', $menuId)
                    ->value('item_id');
                if ($linkedItemId) {
                    $itemId = (int) $linkedItemId;
                }
            } catch (\Throwable $e) {
                // Table might not exist in isolated unit test environments
            }
        }

        if ($itemId !== null && $itemId > 0) {
            try {
                $query = DB::table('item_uoms as iu')
                    ->join('items as i', 'iu.item_id', '=', 'i.id')
                    ->where('iu.item_id', $itemId)
                    ->whereNull('i.deleted_at')
                    ->whereRaw('LOWER(iu.uom_name) = ?', [$clean]);

                if ($outletId !== null && $outletId > 0) {
                    $query->where(function ($q) use ($outletId) {
                        $q->where('i.outlet_id', $outletId)->orWhereNull('i.outlet_id')->orWhere('i.outlet_id', 0);
                    });
                }

                $mapping = $query->select('iu.conversion_factor', 'i.base_uom')->first();
                if ($mapping && (float) $mapping->conversion_factor > 0.0) {
                    $baseUomCanon = $this->normalizeUnit($mapping->base_uom);
                    $baseDim = $this->getDimension($baseUomCanon);

                    if ($baseDim === self::DIMENSION_PORTION || $baseDim === self::DIMENSION_COUNT) {
                        $factor = (float) $mapping->conversion_factor;
                        return [
                            'status'            => self::STATUS_SUPPORTED,
                            'canonical_unit'    => $baseUomCanon,
                            'dimension'         => $baseDim,
                            'conversion_factor' => $factor,
                            'is_discrete'       => true,
                            'price_multiplier'  => round(1.0 / $factor, 6),
                            'failure_reason'    => null,
                        ];
                    }

                    return [
                        'status'            => self::STATUS_UNIT_DIMENSION_MISMATCH,
                        'canonical_unit'    => $baseUomCanon,
                        'dimension'         => $baseDim,
                        'conversion_factor' => (float) $mapping->conversion_factor,
                        'is_discrete'       => false,
                        'price_multiplier'  => 1.0,
                        'failure_reason'    => self::REASON_UNIT_DIMENSION_MISMATCH,
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore DB exception and fall through to fail-closed
            }
        }

        // 4. Custom unit without explicit mapping -> fail closed
        return [
            'status'            => self::STATUS_UNSUPPORTED_DATA,
            'canonical_unit'    => $clean,
            'dimension'         => self::DIMENSION_CUSTOM,
            'conversion_factor' => 1.0,
            'is_discrete'       => false,
            'price_multiplier'  => 1.0,
            'failure_reason'    => self::REASON_UNIT_CONVERSION_UNAVAILABLE,
        ];
    }
}
