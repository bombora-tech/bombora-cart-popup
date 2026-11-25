# Changelog

Formát podle [Keep a Changelog](https://keepachangelog.com/cs/), verze podle [SemVer](https://semver.org/lang/cs/).

## [1.3.0] - 2026-11-25

### Přidáno
- Zdroj doporučených produktů **„Cross-sell → stejná kategorie (bez upsellů)“** (`cross_category`). Up-selly se v e-shopech používají pro varianty balení (Bombora Manager → Varianty balení), proto nepatří do pop-upu.

### Změněno
- Výchozí zdroj pro nové instalace: `cross_category` (dříve `smart`). Uložené nastavení webů se nemění.

## [1.2.0] - 2026-11-23

### Opraveno
- Doporučené produkty: WPC bundle s pevnými položkami (bez variant a volitelných položek) jde přidat do košíku přímo z pop-upu – dříve tlačítko vedlo na stránku bundlu. Bundly s výběrem dál odkazují na detail.

### Přidáno
- Ruský překlad frontendu (`ru_RU`) – zgruzie.cz/ru.
 - 2026-11-23

### Změněno
- Plugin je modulem **Bombora Manageru**: menu Bombora → Pop-up košík, aktualizace, instalace a přehled spravuje Manager.
- Repozitář přesunut na `bombora-tech/bombora-cart-popup` (veřejný) – na webech není potřeba nic konfigurovat.

### Odstraněno
- Vlastní updater a konstanty `BOMBORA_CART_POPUP_REPO` / `BOMBORA_GITHUB_TOKEN` (aktualizace řeší Manager se stejnou SHA-256 kontrolou).

## [1.0.0] - 2026-11-23

### Přidáno
- Pop-up po přidání do košíku z detailu produktu (simple, variabilní, WPC Product Bundles) i z výpisů (AJAX tlačítka WooCommerce).
- Změna množství s okamžitým přepočtem řádku a následným potvrzením ze serveru (mezisoučet, fragmenty hlavičky, WPify lišta).
- Odebrání produktu, mezisoučet, tlačítka Pokračovat / Zobrazit košík / K pokladně.
- Shortcode pod košíkem (jen registrované shortcody, např. `[wpify_woo_free_shipping_notice]`).
- Doporučené produkty: cross-sell → upsell → stejná kategorie, bez produktů v košíku, vyprodaných a skrytých; cache 6 h per jazyk.
- WPML/WCML: dohledání položky napříč jazyky, překlad cross-sellů, admin texty přes `wpml-config.xml`.
- Překlady EN, NL, CS, SK, DE, FR (formát `.l10n.php`).
- Administrace Bombora → Pop-up košík (přepínače, vzhled, texty, stav aktualizací).
- Automatické aktualizace z GitHub Releases s ověřením SHA-256.

### Bezpečnost
- CSRF ochrana kompatibilní s page cache (vlastní hlavička + same-origin kontrola), jen POST.
- Přidání do košíku výhradně přes `WC_Form_Handler`; neplatný požadavek je zastaven před přidáním.
- Striktní validace vstupů, escapovaný výstup, nastavení jen pro `manage_options`.
