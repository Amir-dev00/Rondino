<?php

declare(strict_types=1);

final class SimcartihaAdapter extends BaseAdapter
{
    public function slug(): string { return 'simcartiha'; }
    public function name(): string { return 'simcartiha.ir'; }
    public function baseUrl(): string { return 'https://simcartiha.ir'; }
    public function seedUrls(): array
    {
        $urls = ['https://simcartiha.ir/'];
        for ($i = 0; $i <= 9; $i++) {
            $urls[] = "https://simcartiha.ir/code{$i}/";
        }
        return $urls;
    }
    public function notes(): string { return 'ووکامرس؛ از /codeN/ استفاده شود.'; }

    public function parseListPage(string $html, string $pageUrl): array
    {
        return WooHelpers::parseProductCards(
            $html,
            $pageUrl,
            $this->baseUrl(),
            'simcartiha-store',
            [
                ['phone' => '09122373003', 'type' => 'mobile', 'scope' => 'store', 'label' => 'سیمکارتیها'],
                ['phone' => '02166677836', 'type' => 'landline', 'scope' => 'store', 'label' => 'سیمکارتیها'],
            ],
            fn ($x) => $this->sim($x),
            fn ($x) => $this->price($x),
            fn ($c, $s) => $this->contacts($c, $s),
            fn ($d) => $this->listing($d)
        );
    }
}
