import { InsuranceOutlined } from '@ant-design/icons';

export const accounting = [
    {
        name: 'accountingDropdown',
        meta: {
            icon: <InsuranceOutlined />,
            label: 'Accounting',
        },
    },
    {
        name: 'dashboard',
        list: '/dashboard',
        create: '/dashboard/create',
        edit: '/dashboard/edit/:id',
        meta: {
            parent: 'accountingDropdown',
            label: 'Dashboard',
        },
    },
    {
        name: 'receipts',
        list: '/income',
        create: '/income/create',
        edit: '/income/edit/:id',
        meta: {
            parent: 'accountingDropdown',
            label: 'Income',
        },
    },
    {
        name: 'insurerPaymentDropdown',
        meta: {
            parent: 'accountingDropdown',
            label: 'InsurerPayment',
        },
    },
    {
        name: 'receipts',
        list: '/insurerPayment/record',
        edit: '/insurerPayment/record/edit/:id',
        meta: {
            parent: 'insurerPaymentDropdown',
            label: 'Record',
        },
    },
    {
        name: 'insurers',
        list: '/insurerPayment/summary',
        meta: {
            parent: 'insurerPaymentDropdown',
            label: 'Summary',
        },
    },
    {
        name: 'expensesDropdown',
        meta: {
            parent: 'accountingDropdown',
            label: 'Other Expenses',
        },
    },
    {
        name: 'expenses',
        identifier: 'expenses_record',
        list: '/otherExpenses/record',
        create: '/otherExpenses/record/create',
        edit: '/otherExpenses/record/edit/:id',
        meta: {
            parent: 'expensesDropdown',
            label: 'Record',
        },
    },
    {
        name: 'expenses',
        identifier: 'expenses_summary',
        list: '/otherExpenses/summary',
        show: '/otherExpenses/summary/show/:year/:id',
        meta: {
            parent: 'expensesDropdown',
            label: 'Summary',
        },
    },
];
