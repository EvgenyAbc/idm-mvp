import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
} from "react";
import {
  Form,
  NavLink,
  Outlet,
  useLoaderData,
  useLocation,
  useNavigation,
} from "react-router-dom";
import { useT } from "../../shared/i18n";
import { hasPerm, type Session } from "../../shared/lib/authSession";
import { PERMISSIONS } from "../../shared/lib/permissions";
import { LanguageSwitch } from "../../shared/ui/LanguageSwitch";
import { ModalHost } from "../../shared/ui/modal/ModalHost";
import type { RootOutletContext } from "./types";

function tabClass({
  isActive,
  isPending,
}: {
  isActive: boolean;
  isPending: boolean;
}): string {
  return `tab-link${isActive ? " tab-link-active" : ""}${isPending ? " tab-link-pending" : ""}`;
}

type LoadingRay = {
  color: string;
  durationMs: number;
  thicknessPx: number;
  opacity: number;
};

const RAY_COLORS = [
  "#60a5fa",
  "#22d3ee",
  "#34d399",
  "#f59e0b",
  "#f472b6",
  "#a78bfa",
  "#f97316",
];
const MIN_LOADING_OVERLAY_MS = 300;

function buildLoadingRay(): LoadingRay {
  return {
    color: RAY_COLORS[Math.floor(Math.random() * RAY_COLORS.length)],
    durationMs: 500,
    thicknessPx: 150 + Math.floor(Math.random() * 90),
    opacity: 0.35 + Math.random() * 0.25,
  };
}

export function RootLayoutPage() {
  const t = useT();
  const { session } = useLoaderData() as { session: Session };
  const location = useLocation();
  const navigation = useNavigation();
  const busy = navigation.state !== "idle";
  const loadingStartedAtRef = useRef<number | null>(null);
  const hideTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [overlayActive, setOverlayActive] = useState(false);
  const [loadingRay, setLoadingRay] = useState<LoadingRay>(() =>
    buildLoadingRay(),
  );

  useEffect(() => {
    if (hideTimerRef.current) {
      clearTimeout(hideTimerRef.current);
      hideTimerRef.current = null;
    }

    if (busy) {
      loadingStartedAtRef.current = Date.now();
      setOverlayActive(true);
      setLoadingRay(buildLoadingRay());
      return;
    }

    const loadingStartedAt = loadingStartedAtRef.current;
    if (!loadingStartedAt) {
      setOverlayActive(false);
      return;
    }

    const elapsedMs = Date.now() - loadingStartedAt;
    const remainingMs = Math.max(MIN_LOADING_OVERLAY_MS - elapsedMs, 0);
    hideTimerRef.current = setTimeout(() => {
      setOverlayActive(false);
      hideTimerRef.current = null;
      loadingStartedAtRef.current = null;
    }, remainingMs);
  }, [busy]);

  useEffect(
    () => () => {
      if (hideTimerRef.current) {
        clearTimeout(hideTimerRef.current);
      }
    },
    [],
  );

  useEffect(() => {
    if ("scrollRestoration" in window.history) {
      window.history.scrollRestoration = "manual";
    }
  }, []);

  useLayoutEffect(() => {
    window.scrollTo(0, 0);
    const rafId = window.requestAnimationFrame(() => window.scrollTo(0, 0));
    return () => window.cancelAnimationFrame(rafId);
  }, [location.pathname, location.search, location.hash]);

  const ctx = useMemo<RootOutletContext>(
    () => ({
      session,
      canViewOperations: hasPerm(session, PERMISSIONS.dashboardOperationsView),
      canViewMetrics: hasPerm(session, PERMISSIONS.dashboardMetricsView),
      canViewApprovals: hasPerm(session, PERMISSIONS.dashboardApprovalsView),
      canViewUsers: hasPerm(session, PERMISSIONS.dashboardUsersView),
      canViewLdap: hasPerm(session, PERMISSIONS.dashboardLdapView),
      canViewProfile: hasPerm(session, PERMISSIONS.dashboardProfileView),
      canViewEvents:
        hasPerm(session, PERMISSIONS.dashboardEventsView) &&
        hasPerm(session, PERMISSIONS.metricsEventsView),
    }),
    [session],
  );

  return (
    <div className="layout">
      <div
        className={`global-loading-overlay${overlayActive ? " global-loading-overlay-active" : ""}`}
        role="status"
        aria-live="polite"
        aria-label={
          overlayActive ? t("layout.loadingContent") : t("layout.idle")
        }
      >
        <span
          className="global-loading-ray"
          style={
            {
              "--ray-color": loadingRay.color,
              "--ray-duration": `${loadingRay.durationMs}ms`,
              "--ray-thickness": `${loadingRay.thicknessPx}px`,
              "--ray-opacity": loadingRay.opacity,
            } as CSSProperties
          }
        />
      </div>
      <div className="row header-bar">
        <h1>{t("layout.title")}</h1>
        <div className="header-actions">
          <LanguageSwitch />
          <div className="header-user">
            {t("layout.signedInAs")}{" "}
            <strong>{session.username}</strong>
          </div>
          <Form method="post" action="/logout">
            <button type="submit">{t("layout.logout")}</button>
          </Form>
        </div>
      </div>
      <nav className="tab-bar">
        {ctx.canViewOperations ? (
          <NavLink
            to="/ldap/operations"
            className={tabClass}
            discover="none"
            prefetch="none"
          >
            {t("layout.navOperations")}
          </NavLink>
        ) : null}
        {ctx.canViewMetrics ? (
          <NavLink
            to="/ldap/metrics"
            className={tabClass}
            discover="none"
            prefetch="none"
          >
            {t("layout.navMetrics")}
          </NavLink>
        ) : null}
        {ctx.canViewApprovals ? (
          <NavLink
            to="/ldap/approvals"
            className={tabClass}
            discover="none"
            prefetch="none"
          >
            {t("layout.navApprovals")}
          </NavLink>
        ) : null}
        {ctx.canViewUsers ? (
          <NavLink
            to="/ldap/users"
            className={tabClass}
            discover="none"
            prefetch="none"
          >
            {t("layout.navUsers")}
          </NavLink>
        ) : null}
        {ctx.canViewLdap ? (
          <NavLink
            to="/ldap/explorer"
            className={tabClass}
            discover="none"
            prefetch="none"
          >
            {t("layout.navLdap")}
          </NavLink>
        ) : null}
        {ctx.canViewProfile ? (
          <NavLink
            to="/ldap/profile"
            className={tabClass}
            discover="none"
            prefetch="none"
          >
            {t("layout.navMyProfile")}
          </NavLink>
        ) : null}
        {ctx.canViewEvents ? (
          <NavLink
            to="/ldap/events"
            className={tabClass}
            discover="none"
            prefetch="none"
          >
            {t("layout.navEvents")}
          </NavLink>
        ) : null}
      </nav>
      <div className="layout-content">
        <Outlet context={ctx} />
      </div>
      <footer className="global-footer">
        <span className="muted">{t("layout.footer")}</span>
      </footer>
      <ModalHost />
    </div>
  );
}
