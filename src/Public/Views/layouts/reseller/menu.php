<?php

/**
 * Bootstrap 5 reseller vertical sidebar menu, driven by ResellerNavbarRegistry.
 *
 * Included by header.php (which defines the shared _xc_reseller_nav_visible() /
 * _xc_reseller_nav_label() helpers). Mirrors admin/menu.php's XcNewuiMenuBuilder,
 * against the reseller-only registry so core + module reseller nav entries
 * appear automatically, the way admin's already do.
 */

use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\ResellerNavbarRegistry;
use XcVm\Core\Util\AdminHelpers;

if (count(get_included_files()) == 1) {
    exit();
}

/**
 * Builds the Bootstrap 5 vertical menu markup from the ResellerNavbarRegistry tree.
 *
 * Every render*() returns [html, isActive] so the active leaf and its ancestor
 * groups are resolved in one traversal. Only depth-0 items carry an icon.
 */
if (!class_exists('XcResellerMenuBuilder')) {
    final class XcResellerMenuBuilder {
        /**
         * Detail pages that have no menu entry of their own map to the list
         * page whose menu item should stay highlighted while they are open.
         */
        private const PAGE_ALIASES = [
            'category_template' => 'category_templates',
            'ticket'             => 'tickets',
            'ticket_view'        => 'tickets',
        ];

        public function __construct(
            private bool $mobile,
            private array $settings,
            private string $language,
            private string $page,
        ) {
        }

        /**
         * Full contents of <ul class="menu-inner"> for the registry top level.
         *
         * $sections is an ordered list of ['title' => string, 'keys' => string[]]
         * groups; each renders a Bootstrap 5 `menu-header` (when titled) followed by
         * the listed top-level items in the given order. Any visible top-level
         * item not placed in a section — e.g. one a module registered — falls
         * into a trailing catch-all group, so nothing silently disappears.
         */
        public function renderInner(array $sections = []): string {
            $top = [];
            foreach (ResellerNavbarRegistry::getTopLevel() as $item) {
                if (_xc_reseller_nav_visible($item, $this->mobile, $this->settings)) {
                    $top[$item->key] = $item;
                }
            }

            $html = '';
            $used = [];
            foreach ($sections as $section) {
                $body = '';
                foreach ($section['keys'] ?? [] as $key) {
                    if (!isset($top[$key])) {
                        continue;
                    }
                    [$node] = $this->renderNode($top[$key], 0);
                    $body .= $node;
                    $used[$key] = true;
                }
                if ($body === '') {
                    continue; // no visible items in this section
                }
                $html .= $this->sectionHeader($this->sectionTitle($section['title'] ?? '')) . $body;
            }

            // Trailing catch-all for top-level items no section claimed.
            $rest = '';
            foreach ($top as $key => $item) {
                if (isset($used[$key])) {
                    continue;
                }
                [$node] = $this->renderNode($item, 0);
                $rest .= $node;
            }
            if ($rest !== '') {
                $html .= ($sections ? $this->sectionHeader('More') : '') . $rest;
            }

            return $html;
        }

        /** Translate a section title key ('' passes through untranslated). */
        private function sectionTitle(string $key): string {
            if ($key === '') {
                return '';
            }
            return $this->language::get($key);
        }

        /** A Bootstrap 5 sidebar section caption (empty title renders nothing). */
        private function sectionHeader(string $title): string {
            if ($title === '') {
                return '';
            }
            return '<li class="menu-header small text-uppercase"><span class="menu-header-text">'
                . htmlspecialchars($title, ENT_QUOTES) . '</span></li>';
        }

        /**
         * Whether a menu node's URL points at the page being rendered.
         *
         * Two cases the plain basename match gets wrong:
         *  - `line` and `line?trial=1` share a basename, so both would light up
         *    at once. The trial entry matches only while ?trial=1 is set, and
         *    the plain one only while it is not.
         *  - Detail pages live under their own route (`ticket_view` under
         *    Tickets, `category_template` under Category Templates), which
         *    left the sidebar with nothing highlighted; PAGE_ALIASES folds
         *    them back onto their section.
         */
        private function isCurrent(string $url): bool {
            if ($url === '#' || $url === '') {
                return false;
            }
            $urlBase = basename(explode('?', $url)[0]);
            if ($urlBase === '') {
                return false;
            }

            $isTrialPage = (string) ($_GET['trial'] ?? '') === '1';
            if (str_contains($url, 'trial=1')) {
                return $urlBase === $this->page && $isTrialPage;
            }
            if (in_array($urlBase, ['line', 'mag', 'enigma'], true)) {
                return $urlBase === $this->page && !$isTrialPage;
            }

            $resolvedPage = self::PAGE_ALIASES[$this->page] ?? $this->page;
            return $urlBase === $resolvedPage;
        }

        /**
         * Render a single menu node.
         *
         * @return array{0:string,1:bool} [html, isActive]
         */
        private function renderNode(NavbarItem $item, int $depth): array {
            $hasKids = ResellerNavbarRegistry::hasChildren($item->key) && !($item->noMobileSubmenu && $this->mobile);

            $childHtml = '';
            $childActive = false;
            if ($hasKids) {
                [$childHtml, $childActive] = $this->renderList($item, $depth + 1);
                if ($childHtml === '') {
                    $hasKids = false; // every child was filtered out
                }
            }

            $selfActive = $this->isCurrent($item->url);
            $active     = $selfActive || ($hasKids && $childActive);

            $liClass = 'menu-item' . ($active ? ' active' : '') . ($hasKids && $active ? ' open' : '');
            $href    = htmlspecialchars($hasKids ? 'javascript:void(0);' : $item->url, ENT_QUOTES);
            $icon    = $depth === 0
                ? '<i class="menu-icon icon-base ' . htmlspecialchars((string) ($item->icon ?: 'ti tabler-circle'), ENT_QUOTES) . '"></i>'
                : '';

            $html  = '<li class="' . $liClass . '">';
            $html .= '<a href="' . $href . '" class="menu-link' . ($hasKids ? ' menu-toggle' : '') . '">';
            $html .= $icon . '<div>' . _xc_reseller_nav_label($item, $this->language) . '</div>';
            $html .= '</a>' . $childHtml . '</li>';

            return [$html, $active];
        }

        /**
         * Render the <ul class="menu-sub"> for a parent's visible children.
         *
         * @return array{0:string,1:bool} [html, anyChildActive]
         */
        private function renderList(NavbarItem $parent, int $depth): array {
            $items = '';
            $anyActive = false;
            foreach (ResellerNavbarRegistry::getChildren($parent->key) as $child) {
                if (!_xc_reseller_nav_visible($child, $this->mobile, $this->settings)) {
                    continue;
                }
                [$node, $active] = $this->renderNode($child, $depth);
                $items .= $node;
                $anyActive = $anyActive || $active;
            }
            return $items === '' ? ['', false] : ['<ul class="menu-sub">' . $items . '</ul>', $anyActive];
        }
    }
}

$_resellerMenu = new XcResellerMenuBuilder($rMobile, $rSettings, (string) $language, AdminHelpers::getPageName());

/**
 * Sidebar sections (view-level presentation, Bootstrap 5 `menu-header` captions).
 * Keys are ResellerNavbarRegistry top-level keys — reorder/regroup/rename freely.
 * Any visible top-level item not listed here (e.g. one a module registers)
 * automatically lands in a trailing "More" section, so nothing is lost.
 */
$_resellerMenuSections = [
    ['title' => '',                     'keys' => ['dashboard']],
    ['title' => 'clients',              'keys' => ['user_lines', 'mag_devices', 'enigma_devices', 'active_codes', 'sub_resellers']],
    ['title' => 'content',              'keys' => ['streams', 'created_channels', 'movies', 'episodes', 'radios', 'tv_guide']],
    ['title' => 'category_templates',   'keys' => ['category_templates']],
    ['title' => 'tickets_and_logs',     'keys' => ['tickets', 'logs']],
];
?>
<!-- Vertical menu -->
<aside id="layout-menu" class="layout-menu menu-vertical menu">
    <div class="app-brand demo">
        <a href="dashboard" class="app-brand-link">
            <span class="app-brand-logo demo">
                <img src="assets/img/logo-topbar.png" alt="<?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?>" height="24">
            </span>
            <span class="app-brand-text demo menu-text fw-bold ms-3"><?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?></span>
        </a>
        <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
            <i class="icon-base ti menu-toggle-icon d-none d-xl-block"></i>
            <i class="icon-base ti tabler-x d-block d-xl-none"></i>
        </a>
    </div>

    <div class="menu-inner-shadow"></div>

    <ul class="menu-inner py-1">
        <?= $_resellerMenu->renderInner($_resellerMenuSections); ?>
    </ul>
</aside>
<!-- / Vertical menu -->
