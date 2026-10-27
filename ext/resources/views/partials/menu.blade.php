{{--
    ext fork: sidebar menu, included by one @includeIf line in upstream's
    resources/views/components/layout/sidebar.blade.php (see ext/UPSTREAM_TOUCHPOINTS.md).
    Items appear once their route exists, so later phases only add routes.
--}}
@php
    $extIsOwner = auth()->check() && app(\FireflyIII\Repositories\User\UserRepositoryInterface::class)->hasRole(auth()->user(), 'owner');
    $extItems = array_filter(
        [
            ['route' => 'ext.statements.index', 'active' => 'ext.statements.', 'icon' => 'bi-file-earmark-text', 'label' => 'ext::menu.statements', 'owner' => false],
            ['route' => 'ext.mailboxes.index', 'active' => 'ext.mailboxes.', 'icon' => 'bi-envelope', 'label' => 'ext::menu.mailboxes', 'owner' => false],
            ['route' => 'ext.mail.index', 'active' => 'ext.mail.', 'icon' => 'bi-envelope-paper', 'label' => 'ext::menu.mail', 'owner' => false],
            ['route' => 'ext.accounts.index', 'active' => 'ext.accounts.', 'icon' => 'bi-credit-card', 'label' => 'ext::menu.accounts', 'owner' => false],
            ['route' => 'ext.banks.index', 'active' => 'ext.banks.', 'icon' => 'bi-bank', 'label' => 'ext::menu.banks', 'owner' => false],
            ['route' => 'ext.periods.index', 'active' => 'ext.periods.', 'icon' => 'bi-calendar-range', 'label' => 'ext::menu.periods', 'owner' => false],
            ['route' => 'ext.admin.google.index', 'active' => 'ext.admin.', 'icon' => 'bi-google', 'label' => 'ext::menu.google', 'owner' => true],
        ],
        static fn (array $item): bool => Route::has($item['route']) && (!$item['owner'] || $extIsOwner)
    );
@endphp
<li class="nav-header text-uppercase">{{ __('ext::menu.header') }}</li>
@foreach($extItems as $extItem)
    <li class="nav-item">
        <a href="{{ route($extItem['route']) }}" class="nav-link {{ menu_item_active_partial($extItem['active']) }}">
            <em class="nav-icon bi {{ $extItem['icon'] }}"></em>
            <p>{{ __($extItem['label']) }}</p>
        </a>
    </li>
@endforeach
<li class="nav-item">
    <a href="{{ config('ext.source_url') }}" class="nav-link" rel="noopener" target="_blank">
        <em class="nav-icon bi bi-code-slash"></em>
        <p>{{ __('ext::menu.source') }}</p>
    </a>
</li>
