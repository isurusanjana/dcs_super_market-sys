<?php
// system => [ group => [ [label, route, permission, icon] ] ]
return [
    'admin' => [
        'Overview' => [['Dashboard', 'dashboard/admin', null, 'bi-speedometer2']],
        'People & Security' => [
            ['Users', 'users/index', 'admin.users.view', 'bi-person-gear'], ['Roles & Permissions', 'roles/index', 'admin.roles.view', 'bi-shield-lock'],
            ['Employees', 'crud/list&e=employees', 'admin.employees.view', 'bi-person-badge'], ['Attendance', 'attendance/index', 'admin.attendance.view', 'bi-calendar-check'],
            ['Audit Logs', 'audit/index', 'admin.audit.view', 'bi-journal-text']],
        'Organisation' => [
            ['Branches', 'crud/list&e=branches', 'admin.branches.view', 'bi-shop'], ['Warehouses', 'crud/list&e=warehouses', 'admin.branches.view', 'bi-building'],
            ['POS Counters', 'crud/list&e=counters', 'admin.branches.view', 'bi-pc-display']],
        'Partners' => [['Suppliers', 'crud/list&e=suppliers', 'admin.suppliers.view', 'bi-truck'], ['Customers', 'crud/list&e=customers', 'admin.customers.view', 'bi-people'],
            ['Customer Groups', 'crud/list&e=customer_groups', 'admin.customers.view', 'bi-diagram-3']],
        'Pricing' => [['Price Lists', 'crud/list&e=price_lists', 'admin.pricing.view', 'bi-tags'], ['Promotions', 'crud/list&e=promotions', 'admin.pricing.view', 'bi-megaphone']],
        'Finance' => [['Expenses', 'crud/list&e=expenses', 'admin.finance.view', 'bi-receipt'], ['Transactions', 'finance/transactions', 'admin.finance.view', 'bi-cash-stack'],
            ['Cash Drawer', 'finance/cash', 'admin.finance.view', 'bi-safe']],
        'Reports' => [['Reports', 'reports/index', 'admin.reports.view', 'bi-bar-chart-line']],
        'System' => [['Settings', 'settings/index', 'admin.settings.view', 'bi-gear']],
    ],
    'pos' => [
        'Point of Sale' => [['Dashboard', 'dashboard/pos', null, 'bi-speedometer2'], ['Register / Session', 'pos/session', 'pos.session.open', 'bi-door-open'],
            ['POS Terminal', 'pos/terminal', 'pos.sale.create', 'bi-cart4'], ['Returns & Exchanges', 'pos/returns', 'pos.returns.create', 'bi-arrow-return-left'],
            ['Gift Cards', 'giftcards/index', 'pos.giftcard.view', 'bi-gift'], ['Cash Pickup / Paid out', 'pos/cash', 'pos.session.open', 'bi-cash-stack'],
            ['Transactions', 'pos/history', 'pos.history.view', 'bi-clock-history']],
    ],
    'inventory' => [
        'Overview' => [['Dashboard', 'dashboard/inventory', null, 'bi-speedometer2'], ['Alerts', 'alerts/index', 'inventory.alerts.view', 'bi-bell']],
        'Catalogue' => [['All Products', 'crud/list&e=products', 'inventory.products.view', 'bi-box-seam'], ['Groceries & Household', 'crud/list&e=groceries', 'inventory.products.view', 'bi-basket'], ['Fresh Foods', 'crud/list&e=fresh', 'inventory.products.view', 'bi-apple'], ['Books', 'crud/list&e=books', 'inventory.books.view', 'bi-book'],
            ['Stationery', 'crud/list&e=stationery', 'inventory.stationery.view', 'bi-pencil'], ['Categories', 'crud/list&e=categories', 'inventory.categories.view', 'bi-diagram-2'],
            ['Brands', 'crud/list&e=brands', 'inventory.categories.view', 'bi-bookmark-star'], ['Authors', 'crud/list&e=authors', 'inventory.categories.view', 'bi-person-lines-fill'],
            ['Publishers', 'crud/list&e=publishers', 'inventory.categories.view', 'bi-building-gear'], ['Units', 'crud/list&e=units', 'inventory.categories.view', 'bi-rulers'],
            ['Barcode Labels', 'products/labels', 'inventory.products.view', 'bi-upc-scan']],
        'Stock' => [['Stock Levels', 'stock/index', 'inventory.stock.view', 'bi-boxes'], ['Adjustments', 'stock/adjustments', 'inventory.stock.view', 'bi-sliders'],
            ['Movements', 'stock/movements', 'inventory.stock.view', 'bi-arrow-left-right'], ['Expiry & Batches', 'expiry/index', 'inventory.expiry.view', 'bi-hourglass-split'], ['Stocktake', 'stocktake/index', 'inventory.stocktake.view', 'bi-clipboard-check'],
            ['Transfers', 'transfers/index', 'inventory.transfers.view', 'bi-box-arrow-right']],
        'Purchasing' => [['Purchase Orders', 'purchasing/index', 'inventory.purchasing.view', 'bi-cart-plus'], ['Goods Received', 'purchasing/grns', 'inventory.purchasing.view', 'bi-box-arrow-in-down'],
            ['Purchase Invoices', 'purchasing/invoices', 'inventory.purchasing.view', 'bi-file-earmark-text'], ['Purchase Returns', 'purchasing/returns', 'inventory.purchasing.view', 'bi-arrow-counterclockwise']],
    ],
];
