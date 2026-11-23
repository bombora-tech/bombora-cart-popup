# Bombora Cart Popup

Rychlý a bezpečný pop-up košík pro WooCommerce (Bombora group s.r.o.). Náhrada za XootiX Cart Popup.

- 1× CSS (~5 kB) + 1× JS (~5 kB, bez jQuery závislosti, `defer`), žádné externí knihovny.
- Kompatibilní s WPML/WCML, WPC Product Bundles, Bricks, full-page cache (WP Fastest Cache, Perfmatters).
- Nastavení: **Bombora → Pop-up košík**.

## Instalace
1. Stáhněte `bombora-cart-popup.zip` z [Releases](../../releases) a nahrajte v *Pluginy → Přidat nový → Nahrát*.
2. Pro **privátní** repozitář přidejte do `wp-config.php`:
   ```php
   define( 'BOMBORA_GITHUB_TOKEN', 'github_pat_…' ); // fine-grained token: jen tento repozitář, Contents = Read-only
   ```
3. Jiný repozitář (fork): `define( 'BOMBORA_CART_POPUP_REPO', 'owner/repo' );`

## Vydání nové verze
1. Zvyšte verzi v hlavičce `bombora-cart-popup.php` **i** v `BCP_VERSION`.
2. Doplňte sekci do `CHANGELOG.md`.
3. `git tag vX.Y.Z && git push --tags` → GitHub Action sestaví ZIP + `.sha256` a vytvoří Release.
4. Weby uvidí aktualizaci do 6 h (nebo ihned: *Bombora → Pop-up košík → Zkontrolovat aktualizace*).

Ručně: `./build.sh` → `dist/`.

## Bezpečnost
| Oblast | Opatření |
|---|---|
| CSRF | Vlastní hlavička `X-Bombora-Cart` (cross-site ji nelze poslat bez CORS) + kontrola `Origin` / `Sec-Fetch-Site` / `Referer`, jen POST. Nonce se nepoužívá záměrně – stránky jsou cachované. |
| Košík | Přidání výhradně přes `WC_Form_Handler` (všechny validace WooCommerce a pluginů); změna množství ověřuje klíč, min/max, sklad, `woocommerce_update_cart_validation`. |
| Vstupy | `absint`, regex na klíč košíku (`^[a-f0-9]{32}$`), limity množství. |
| Výstup | `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post`; JS vkládá texty přes `textContent`. |
| Nastavení | Jen `manage_options`, Settings API (nonce), whitelist klíčů, shortcode pole přijme jen registrované shortcody. |
| Aktualizace | Jen HTTPS a povolení hostitelé GitHubu, SHA-256 kontrola ZIPu (`hash_equals`), token jen z `wp-config.php`, hlavička `Update URI`. |

## Filtry
- `bcp_should_load` (bool) – vypnout načítání na konkrétních stránkách.
- `bcp_related_pool` (int[] $ids, int $product_id) – upravit kandidáty doporučení.
- `bcp_allowed_hosts` (string[]) – další povolené domény pro same-origin kontrolu.
- `bombora_admin_plugins` – karty v přehledu Bombora.
