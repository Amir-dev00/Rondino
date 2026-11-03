<?php

namespace App\Scraping;

use App\Scraping\Adapters\CafesimcartAdapter;
use App\Scraping\Adapters\DonyayekhatAdapter;
use App\Scraping\Adapters\Endline912Adapter;
use App\Scraping\Adapters\Iran912Adapter;
use App\Scraping\Adapters\JamsimkartAdapter;
use App\Scraping\Adapters\Rond0912Adapter;
use App\Scraping\Adapters\RondbazAdapter;
use App\Scraping\Adapters\RondIrAdapter;
use App\Scraping\Adapters\SetaresimAdapter;
use App\Scraping\Adapters\Sim0912Adapter;
use App\Scraping\Adapters\SimcartihaAdapter;
use App\Scraping\Adapters\SimkhanAdapter;
use App\Scraping\Adapters\TehransimAdapter;
use App\Scraping\Contracts\SourceAdapterInterface;
use InvalidArgumentException;

final class AdapterRegistry
{
    /**
     * @return array<string, class-string<SourceAdapterInterface>>
     */
    public static function map(): array
    {
        return [
            'rond-ir' => RondIrAdapter::class,
            'tehransim' => TehransimAdapter::class,
            'iran912' => Iran912Adapter::class,
            'simcartiha' => SimcartihaAdapter::class,
            'endline912' => Endline912Adapter::class,
            'donyayekhat' => DonyayekhatAdapter::class,
            'cafesimcart' => CafesimcartAdapter::class,
            'rond0912' => Rond0912Adapter::class,
            'simkhan' => SimkhanAdapter::class,
            'setaresim' => SetaresimAdapter::class,
            'jamsimkart' => JamsimkartAdapter::class,
            'rondbaz' => RondbazAdapter::class,
            'sim0912' => Sim0912Adapter::class,
        ];
    }

    public static function make(string $slug): SourceAdapterInterface
    {
        $map = self::map();
        if (! isset($map[$slug])) {
            throw new InvalidArgumentException('Unknown source slug: '.$slug);
        }

        return new $map[$slug];
    }

    /**
     * @return list<SourceAdapterInterface>
     */
    public static function all(): array
    {
        return array_map(fn (string $class) => new $class, array_values(self::map()));
    }
}
