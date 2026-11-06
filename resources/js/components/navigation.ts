import type { LucideIcon } from 'lucide-react'
import { BarChart3, Banknote, History, KeyRound, Users, Boxes, ChartColumnBig, Database, FileStack, LayoutDashboard, Settings, ShoppingCart, Store, Truck, Upload } from 'lucide-react'

export type NavItem = { title: string; url: string; icon: LucideIcon; permission: string }
export type NavGroup = { label: string; items: NavItem[] }

// Items are added here as each module ships (see docs/progress.md).
export const navigation: NavGroup[] = [
  {
    label: 'Overview',
    items: [
      { title: 'Dashboard', url: '/', icon: LayoutDashboard, permission: 'dashboard.view' },
      { title: 'Analyzer', url: '/analyzer', icon: ChartColumnBig, permission: 'analyzer.view' },
    ],
  },
  {
    label: 'Requisitions',
    items: [
      { title: 'Requisitions', url: '/requisitions', icon: FileStack, permission: 'requisitions.view' },
      { title: 'Upload', url: '/requisitions/upload', icon: Upload, permission: 'requisitions.upload' },
    ],
  },
  {
    label: 'Procurement',
    items: [
      { title: 'Purchase orders', url: '/purchase-orders', icon: ShoppingCart, permission: 'purchases.view' },
      { title: 'Deliveries', url: '/deliveries', icon: Truck, permission: 'deliveries.view' },
      { title: 'Vendors', url: '/vendors', icon: Store, permission: 'vendors.view' },
      { title: 'Payments', url: '/payments', icon: Banknote, permission: 'payments.view' },
    ],
  },
  {
    label: 'Insights',
    items: [{ title: 'Reports', url: '/reports', icon: BarChart3, permission: 'reports.view' }],
  },
  {
    label: 'Catalog',
    items: [
      { title: 'Items', url: '/items', icon: Boxes, permission: 'items.view' },
      { title: 'Master data', url: '/master-data', icon: Database, permission: 'items.view' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { title: 'Users', url: '/users', icon: Users, permission: 'users.manage' },
      { title: 'Roles & permissions', url: '/roles', icon: KeyRound, permission: 'users.manage' },
      { title: 'Activity log', url: '/activity-logs', icon: History, permission: 'activity_logs.view' },
      { title: 'Settings', url: '/settings', icon: Settings, permission: 'settings.manage' },
    ],
  },
]
