import { Navigate, useParams } from "react-router"
import {
  AccountPanel,
  ConnectedAccountsPanel,
  NotificationsPanel,
  PreferencesPanel,
  ProfilePanel,
  SecurityPanel,
} from "@/components/settings/SettingsPanels"
import { NavTabs } from "@/components/shared/FilterTabs"
import { PageHeader } from "@/components/shared/PageHeader"

const TABS = [
  { value: "profile", label: "Profil", panel: ProfilePanel },
  { value: "account", label: "Compte", panel: AccountPanel },
  { value: "security", label: "Sécurité", panel: SecurityPanel },
  { value: "notifications", label: "Notifications", panel: NotificationsPanel },
  { value: "accounts", label: "Comptes connectés", panel: ConnectedAccountsPanel },
  { value: "preferences", label: "Préférences", panel: PreferencesPanel },
] as const

export default function SettingsPage() {
  const { tab = "profile" } = useParams()
  const current = TABS.find((t) => t.value === tab)
  if (!current) return <Navigate to="/settings/profile" replace />
  const Panel = current.panel

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={tab === "security" ? "Sécurité" : "Paramètres"} description="Gérez votre profil, votre sécurité et vos préférences." />
      <NavTabs label="Sections des paramètres" className="mb-6" items={TABS.map((t) => ({ to: `/settings/${t.value}`, label: t.label }))} />
      <Panel />
    </div>
  )
}
