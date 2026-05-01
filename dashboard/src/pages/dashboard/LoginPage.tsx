import { useState } from "react";
import { Form, useActionData, useNavigation } from "react-router-dom";
import { useT } from "../../shared/i18n";
import {
  LOGIN_USERS,
  QUICK_LOGIN_ADMIN_USERNAME,
} from "../../shared/lib/authSession";
import { LanguageSwitch } from "../../shared/ui/LanguageSwitch";
import { ModalHost } from "../../shared/ui/modal/ModalHost";

export function LoginPage() {
  const t = useT();
  const actionData = useActionData() as
    | { ok: false; messageKey: string; params?: Record<string, string | number> }
    | undefined;
  const navigation = useNavigation();
  const busy = navigation.state === "submitting";
  const [quick, setQuick] = useState<{ username: string; password: string }>({
    username: "",
    password: "",
  });

  return (
    <div className="login-page">
      <div className="login-backdrop" />
      <div className="login-panel">
        <header className="login-header">
          <div className="login-lang-switch">
            <LanguageSwitch />
          </div>
          <p className="login-brand">{t("login.brand")}</p>
          <h1 className="login-title">{t("login.title")}</h1>
          <p className="login-subtitle">{t("login.subtitle")}</p>
        </header>
        <Form method="post" replace className="login-form">
          <label className="login-field">
            <span className="login-label">{t("login.username")}</span>
            <input
              className="login-input"
              name="username"
              autoComplete="username"
              placeholder={t("login.placeholderUsername")}
              defaultValue={quick.username}
            />
          </label>
          <label className="login-field">
            <span className="login-label">{t("login.password")}</span>
            <input
              className="login-input"
              name="password"
              type="password"
              autoComplete="current-password"
              placeholder={t("login.placeholderPassword")}
              defaultValue={quick.password}
            />
          </label>
          {actionData?.messageKey ? (
            <p className="login-error">
              {t(actionData.messageKey, actionData.params)}
            </p>
          ) : null}
          <button className="login-submit" type="submit" disabled={busy}>
            {busy ? t("login.signingIn") : t("login.signIn")}
          </button>
        </Form>
        <div className="login-divider">{t("login.divider")}</div>
        <p className="login-dev-hint">{t("login.devHint")}</p>
        <div className="quick-login login-quick">
          {LOGIN_USERS.map((u) => (
            <button
              className="login-chip"
              key={u.username}
              type="button"
              onClick={() =>
                setQuick({ username: u.username, password: u.password })
              }
            >
              {u.username === QUICK_LOGIN_ADMIN_USERNAME
                ? t("login.adminChip")
                : t("login.chip", { username: u.username })}
            </button>
          ))}
        </div>
      </div>
      <ModalHost />
    </div>
  );
}
