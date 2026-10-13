<?php
// Single source of truth for RBAC: module => feature => [label, actions]
// Permission code = module.feature.action
$std = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'];
return [
    'admin' => ['label' => 'Administration', 'features' => [
        'users'     => ['User Management', $std + ['activate' => 'Activate/Deactivate', 'reset_password' => 'Reset Password', 'assign_role' => 'Assign Roles', 'lock' => 'Lock/Unlock']],
        'roles'     => ['Roles & Permissions', $std + ['assign_permission' => 'Assign Permissions']],
        'employees' => ['Employees', $std],
        'attendance'=> ['Attendance', ['view' => 'View History', 'record' => 'Record']],
        'branches'  => ['Branches, Warehouses & Counters', $std],
        'suppliers' => ['Suppliers', $std + ['payment' => 'Record Payment', 'products' => 'Assign Products']],
        'customers' => ['Customers', $std + ['payment' => 'Record Payment', 'loyalty' => 'Adjust Loyalty']],
        'pricing'   => ['Pricing & Promotions', $std],
        'finance'   => ['Finance & Expenses', ['view' => 'View', 'create' => 'Create Expense', 'edit' => 'Edit Expense', 'delete' => 'Delete Expense', 'approve' => 'Approve Expense', 'cash' => 'Cash Adjustments', 'reconcile' => 'Reconcile Cash']],
        'reports'   => ['Reports & Dashboard', ['view' => 'View', 'export' => 'Export/Print']],
        'audit'     => ['Audit Logs', ['view' => 'View']],
        'settings'  => ['System Settings', ['view' => 'View', 'edit' => 'Edit']],
    ]],
    'pos' => ['label' => 'POS', 'features' => [
        'cash'     => ['Cash Management (safe drops)', ['pickup' => 'Cash pickup / safe drop', 'paid_out' => 'Paid out', 'float_in' => 'Float in']],
        'giftcard' => ['Gift Cards & Vouchers', ['view' => 'Check balance', 'sell' => 'Sell / issue', 'block' => 'Block / adjust']],
        'session'  => ['Cashier Session', ['open' => 'Open/Close Register', 'view_all' => 'View All Sessions']],
        'sale'     => ['Sales', ['create' => 'Create Sale', 'credit' => 'Credit Sales', 'price_override' => 'Change Price']],
        'discount' => ['Discounts', ['apply' => 'Apply Manual Discount', 'approve' => 'Approve Discounts (Manager)']],
        'returns'  => ['Returns & Exchanges', ['create' => 'Process Return', 'approve' => 'Approve Returns (Manager)']],
        'history'  => ['Transaction History', ['view' => 'View', 'reprint' => 'Reprint', 'void' => 'Void Transaction', 'view_all' => 'View All Cashiers']],
    ]],
    'inventory' => ['label' => 'Product & Inventory', 'features' => [
        'products'   => ['Products', $std + ['price' => 'Change Prices']],
        'books'      => ['Books', $std],
        'stationery' => ['Stationery', $std],
        'categories' => ['Categories, Brands, Authors, Publishers', $std],
        'stock'      => ['Stock', ['view' => 'View', 'adjust' => 'Adjust (request)', 'approve' => 'Approve Adjustments']],
        'purchasing' => ['Purchasing', ['view' => 'View', 'create' => 'Create PO/Return', 'approve' => 'Approve PO/Return', 'receive' => 'Receive Goods', 'invoice' => 'Purchase Invoices']],
        'transfers'  => ['Stock Transfers', ['view' => 'View', 'create' => 'Create', 'approve' => 'Approve', 'dispatch' => 'Dispatch', 'receive' => 'Receive']],
        'stocktake'  => ['Stocktake', ['view' => 'View', 'create' => 'Create/Count', 'approve' => 'Approve Variance']],
        'expiry'     => ['Expiry, Batches & Wastage', ['view' => 'View', 'markdown' => 'Mark down near-expiry items', 'writeoff' => 'Write off expired / spoiled stock']],
        'alerts'     => ['Inventory Alerts', ['view' => 'View', 'request' => 'Create Purchase Requests']],
    ]],
];
