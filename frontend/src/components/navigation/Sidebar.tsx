import { ChevronsUpDown, LogOut, Settings, UserRound } from "lucide-react"
import { Link, NavLink, useLocation, useNavigate } from "react-router"
import { Logo, LogoMark } from "@/components/brand/Logo"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { useMe, useNotifications } from "@/hooks/queries/misc"
import { initials } from "@/lib/format"
import { cn } from "@/lib/utils"
import { MAIN_NAV, SECONDARY_NAV, type NavItem } from "./navItems"

interface SidebarProps {
  /** rail = icônes seules (tablette) ; full = libellés (desktop et tiroir mobile). */
  mode: "full" | "rail"
  onNavigate?: () => void
}

function useIsActive() {
  const { pathname } = useLocation()
  return (item: NavItem) => {
    // « Paramètres » ne doit pas être actif sur /settings/security (entrée « Sécurité » dédiée).
    if (item.to === "/settings") return pathname.startsWith("/settings") && pathname !== "/settings/security"
    if (item.also?.includes(pathname)) return true
    // L'édition d'une publication existante relève de « Publications ».
    if (item.to === "/content" && /^\/content\/[^/]+\/edit$/.test(pathname)) return true
    if (item.end) return pathname === item.to
    return pathname === item.to || pathname.startsWith(`${item.to}/`)
  }
}

function NavEntry({ item, mode, active, unread, onNavigate }: { item: NavItem; mode: SidebarProps["mode"]; active: boolean; unread: number; onNavigate?: () => void }) {
  const Icon = item.icon
  const showBadge = item.badge === "notifications" && unread > 0
  const link = (
    <NavLink
      to={item.to}
      end={item.end}
      onClick={onNavigate}
      aria-current={active ? "page" : undefined}
      className={cn(
        "group relative flex items-center gap-3 rounded-xl text-[14px] font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring",
        mode === "full" ? "h-11 px-3.5" : "mx-auto size-12 justify-center",
        active
          ? "bg-sidebar-primary text-white shadow-brand"
          : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-white",
      )}
    >
      <Icon className="size-[19px] shrink-0" aria-hidden="true" />
      {mode === "full" ? (
        <>
          <span className="truncate">{item.label}</span>
          {showBadge && (
            <span className="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-danger-600 px-1.5 text-[11px] font-semibold text-white">
              {unread}
              <span className="sr-only"> notifications non lues</span>
            </span>
          )}
        </>
      ) : (
        <>
          <span className="sr-only">{item.label}</span>
          {showBadge && <span className="absolute top-2 right-2 size-2.5 rounded-full bg-danger-500 ring-2 ring-sidebar" aria-label={`${unread} non lues`} />}
        </>
      )}
    </NavLink>
  )
  if (mode === "full") return link
  return (
    <Tooltip>
      <TooltipTrigger asChild>{link}</TooltipTrigger>
      <TooltipContent side="right">{item.label}</TooltipContent>
    </Tooltip>
  )
}

export function Sidebar({ mode, onNavigate }: SidebarProps) {
  const isActive = useIsActive()
  const navigate = useNavigate()
  const { data: me } = useMe()
  const { data: notifications } = useNotifications()
  const unread = notifications?.filter((n) => !n.readAt).length ?? 0

  return (
    <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
      <div className={cn("flex h-[72px] shrink-0 items-center", mode === "full" ? "px-6" : "justify-center")}>
        <Link to="/dashboard" onClick={onNavigate} className="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring" aria-label="SocialPulse — Tableau de bord">
          {mode === "full" ? <Logo tone="light" /> : <LogoMark />}
        </Link>
      </div>

      <nav aria-label="Navigation principale" className="scrollbar-thin flex flex-1 flex-col overflow-y-auto px-3 pb-4">
        <ul className="flex flex-col gap-1">
          {MAIN_NAV.map((item) => (
            <li key={item.to}>
              <NavEntry item={item} mode={mode} active={isActive(item)} unread={unread} onNavigate={onNavigate} />
            </li>
          ))}
        </ul>
        <ul className="mt-auto flex flex-col gap-1 pt-6">
          {SECONDARY_NAV.map((item) => (
            <li key={item.to}>
              <NavEntry item={item} mode={mode} active={isActive(item)} unread={0} onNavigate={onNavigate} />
            </li>
          ))}
        </ul>
      </nav>

      <div className="shrink-0 border-t border-sidebar-border p-3">
        <DropdownMenu>
          <DropdownMenuTrigger
            className={cn(
              "flex w-full items-center gap-3 rounded-xl p-2 text-left transition-colors outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring",
              mode === "rail" && "justify-center",
            )}
            aria-label="Menu du compte"
          >
            <Avatar className="size-10 ring-2 ring-navy-700">
              <AvatarFallback className="bg-brand-gradient text-sm font-semibold text-white">{initials(me?.name ?? "Marie Dubois")}</AvatarFallback>
            </Avatar>
            {mode === "full" && (
              <>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-semibold text-white">{me?.name ?? "…"}</span>
                  <span className="block truncate text-xs text-navy-300">{me?.role}</span>
                </span>
                <ChevronsUpDown className="size-4 text-navy-300" aria-hidden="true" />
              </>
            )}
          </DropdownMenuTrigger>
          <DropdownMenuContent side={mode === "rail" ? "right" : "top"} align="start" className="w-56">
            <DropdownMenuLabel className="truncate">{me?.email}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => { onNavigate?.(); navigate("/settings/profile") }}>
              <UserRound /> Mon profil
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => { onNavigate?.(); navigate("/settings") }}>
              <Settings /> Paramètres
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem variant="destructive" onSelect={() => navigate("/login")}>
              <LogOut /> Se déconnecter
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}
