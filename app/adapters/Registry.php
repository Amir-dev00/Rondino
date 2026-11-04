<?php

declare(strict_types=1);

final class Registry
{
    /** @var array<string, AdapterInterface>|null */
    private static ?array $map = null;

    /** @return array<string, AdapterInterface> */
    public static function all(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $files = [
            'RondIrAdapter.php',
            'TehransimAdapter.php',
            'Iran912Adapter.php',
            'SimcartihaAdapter.php',
            'Endline912Adapter.php',
            'DonyayekhatAdapter.php',
            'CafesimcartAdapter.php',
            'Rond0912Adapter.php',
            'SimkhanAdapter.php',
            'SetaresimAdapter.php',
            'JamsimkartAdapter.php',
            'RondbazAdapter.php',
            'Sim0912Adapter.php',
            'WooHelpers.php',
        ];
        foreach ($files as $f) {
            require_once __DIR__ . '/' . $f;
        }

        /** @var list<AdapterInterface> $adapters */
        $adapters = [
            new RondIrAdapter(),
            new TehransimAdapter(),
            new Iran912Adapter(),
            new SimcartihaAdapter(),
            new Endline912Adapter(),
            new DonyayekhatAdapter(),
            new CafesimcartAdapter(),
            new Rond0912Adapter(),
            new SimkhanAdapter(),
            new SetaresimAdapter(),
            new JamsimkartAdapter(),
            new RondbazAdapter(),
            new Sim0912Adapter(),
        ];

        self::$map = [];
        foreach ($adapters as $a) {
            self::$map[$a->slug()] = $a;
        }
        return self::$map;
    }

    public static function get(string $slug): ?AdapterInterface
    {
        return self::all()[$slug] ?? null;
    }
}
