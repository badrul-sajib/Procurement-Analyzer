import { createBrowserRouter } from 'react-router'
import { AppLayout } from '@/components/app-layout'
import { RequirePermission } from '@/components/require-permission'
import { LoginPage } from '@/features/auth/login-page'

/** Lazy page with a UI permission guard (the API enforces the same permission). */
function page(permission: string, load: () => Promise<React.ComponentType>) {
  return {
    lazy: async () => {
      const Page = await load()
      return { Component: () => <RequirePermission permission={permission}><Page /></RequirePermission> }
    },
  }
}

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: <AppLayout />,
    children: [
      { index: true, ...page('dashboard.view', async () => (await import('@/features/dashboard/dashboard-page')).DashboardPage) },
      { path: 'requisitions', ...page('requisitions.view', async () => (await import('@/features/requisitions/requisitions-page')).RequisitionsPage) },
      { path: 'requisitions/upload', ...page('requisitions.upload', async () => (await import('@/features/requisitions/upload-page')).UploadPage) },
      { path: 'requisitions/new', ...page('requisitions.upload', async () => (await import('@/features/requisitions/requisition-page')).NewRequisitionPage) },
      { path: 'requisitions/:id', ...page('requisitions.view', async () => (await import('@/features/requisitions/requisition-page')).RequisitionPage) },
      { path: 'analyzer', ...page('analyzer.view', async () => (await import('@/features/analyzer/analyzer-page')).AnalyzerPage) },
      { path: 'purchase-orders', ...page('purchases.view', async () => { const { PurchaseOrdersPage } = await import('@/features/purchases/purchase-orders-page'); return () => <PurchaseOrdersPage /> }) },
      { path: 'purchase-orders/new', ...page('purchases.create', async () => (await import('@/features/purchases/purchase-order-form-page')).PurchaseOrderFormPage) },
      { path: 'purchase-orders/:id/edit', ...page('purchases.update', async () => (await import('@/features/purchases/purchase-order-form-page')).PurchaseOrderFormPage) },
      { path: 'purchase-orders/:id', ...page('purchases.view', async () => (await import('@/features/purchases/purchase-order-page')).PurchaseOrderPage) },
      { path: 'deliveries', ...page('deliveries.view', async () => (await import('@/features/deliveries/deliveries-page')).DeliveriesPage) },
      { path: 'deliveries/:id', ...page('deliveries.view', async () => (await import('@/features/deliveries/delivery-page')).DeliveryPage) },
      { path: 'payments', ...page('payments.view', async () => { const { PaymentsPage } = await import('@/features/payments/payments-page'); return () => <PaymentsPage /> }) },
      { path: 'payments/new', ...page('payments.create', async () => (await import('@/features/payments/payment-form-page')).PaymentFormPage) },
      { path: 'payments/:id', ...page('payments.view', async () => (await import('@/features/payments/payment-page')).PaymentPage) },
      { path: 'reports', ...page('reports.view', async () => (await import('@/features/reports/reports-page')).ReportsPage) },
      { path: 'items', ...page('items.view', async () => (await import('@/features/items/items-page')).ItemsPage) },
      { path: 'items/:id', ...page('items.view', async () => (await import('@/features/items/item-detail-page')).ItemDetailPage) },
      { path: 'vendors', ...page('vendors.view', async () => (await import('@/features/vendors/vendors-page')).VendorsPage) },
      { path: 'vendors/:id', ...page('vendors.view', async () => (await import('@/features/vendors/vendor-detail-page')).VendorDetailPage) },
      { path: 'master-data', ...page('items.view', async () => (await import('@/features/master-data/master-data-page')).MasterDataPage) },
      { path: 'users', ...page('users.manage', async () => (await import('@/features/admin/users-page')).UsersPage) },
      { path: 'roles', ...page('users.manage', async () => (await import('@/features/admin/roles-page')).RolesPage) },
      { path: 'activity-logs', ...page('activity_logs.view', async () => (await import('@/features/admin/activity-logs-page')).ActivityLogsPage) },
      { path: 'settings', ...page('settings.manage', async () => (await import('@/features/settings/settings-page')).SettingsPage) },
    ],
  },
])
