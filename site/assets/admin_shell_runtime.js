(() => {
    const TOAST_TYPES = new Set(["success", "danger", "warning", "info"]);
    const TOAST_TYPE_ALIASES = {
        error: "danger",
        alert: "warning",
        notice: "info",
    };
    const TOAST_TITLES = {
        success: "Готово",
        danger: "Ошибка",
        warning: "Внимание",
        info: "Информация",
    };
    const TOAST_TIMEOUTS = {
        success: 5000,
        info: 5000,
        warning: 8000,
        danger: 0,
    };
    const CONFIRM_TONES = {
        danger: "btn-danger-solid",
        warning: "btn-warning-solid",
        primary: "btn-primary",
    };

    let pendingConfirm = null;

    function normalizeToastType(type) {
        const normalizedType = TOAST_TYPE_ALIASES[type] || type;

        return TOAST_TYPES.has(normalizedType) ? normalizedType : "info";
    }

    function getToastStack() {
        let stack = document.getElementById("admin-toast-stack");

        if (!stack) {
            stack = document.createElement("div");
            stack.id = "admin-toast-stack";
            stack.className = "toast-stack";
            stack.setAttribute("role", "region");
            stack.setAttribute("aria-label", "Уведомления");
            stack.dataset.adminToastStack = "";
            document.body.prepend(stack);
        }

        return stack;
    }

    function getLiveRegion() {
        return document.getElementById("admin-live-region");
    }

    function iconMarkup(type) {
        if (type === "success") {
            return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
        }

        if (type === "danger") {
            return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>';
        }

        if (type === "warning") {
            return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
        }

        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
    }

    function closeToast(toast) {
        if (!toast) return;

        window.clearTimeout(Number(toast.dataset.adminToastTimer || 0));
        toast.remove();
    }

    function scheduleToast(toast, autohide, type) {
        const timeout = Number.isFinite(autohide) ? autohide : TOAST_TIMEOUTS[type];

        if (timeout > 0) {
            const timer = window.setTimeout(() => closeToast(toast), timeout);
            toast.dataset.adminToastTimer = String(timer);
        }
    }

    function announce(text) {
        const region = getLiveRegion();

        if (region && text) {
            region.textContent = "";
            window.setTimeout(() => {
                region.textContent = text;
            }, 20);
        }
    }

    function createToast(options = {}) {
        const type = normalizeToastType(options.type);
        const toast = document.createElement("div");
        const title = options.title || TOAST_TITLES[type];
        const description = options.description || options.message || "";

        toast.className = `toast toast--${type}`;
        toast.dataset.adminToast = "";
        toast.dataset.adminToastType = type;

        const icon = document.createElement("span");
        icon.className = "t-ico";
        icon.setAttribute("aria-hidden", "true");
        icon.innerHTML = iconMarkup(type);

        const body = document.createElement("div");
        body.className = "t-body";

        const titleEl = document.createElement("div");
        titleEl.className = "t-title";
        titleEl.textContent = title;

        const descEl = document.createElement("div");
        descEl.className = "t-desc";
        descEl.textContent = description;

        body.append(titleEl, descEl);
        toast.append(icon, body);

        if (options.actionLabel) {
            const action = options.actionHref ? document.createElement("a") : document.createElement("button");
            action.className = "t-action";
            action.textContent = options.actionLabel;

            if (options.actionHref) {
                action.href = options.actionHref;
            } else {
                action.type = "button";
                action.addEventListener("click", () => {
                    if (typeof options.onAction === "function") {
                        options.onAction();
                    }
                    closeToast(toast);
                });
            }

            toast.append(action);
        }

        const closeButton = document.createElement("button");
        closeButton.type = "button";
        closeButton.className = "t-close";
        closeButton.setAttribute("aria-label", "Закрыть");
        closeButton.dataset.adminToastClose = "";
        closeButton.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';
        toast.append(closeButton);

        getToastStack().append(toast);
        scheduleToast(toast, options.autohide, type);
        announce(description || title);

        return toast;
    }

    function getDialog(dialogOrId) {
        if (typeof dialogOrId === "string") {
            return document.getElementById(dialogOrId);
        }

        return dialogOrId;
    }

    function openDialog(dialogOrId) {
        const dialog = getDialog(dialogOrId);

        if (!dialog) return null;

        if (typeof dialog.showModal === "function") {
            if (!dialog.open) {
                dialog.showModal();
            }
        } else {
            dialog.setAttribute("open", "");
        }

        return dialog;
    }

    function closeDialog(dialogOrId, returnValue = "") {
        const dialog = getDialog(dialogOrId);

        if (!dialog) return;

        if (typeof dialog.close === "function") {
            if (dialog.open) {
                dialog.close(returnValue);
            }
        } else {
            dialog.returnValue = returnValue;

            if (dialog.hasAttribute("open")) {
                dialog.removeAttribute("open");
                dialog.dispatchEvent(new Event("close"));
            }
        }
    }

    function resolveConfirm(value) {
        if (!pendingConfirm) return;

        const { resolve } = pendingConfirm;
        pendingConfirm = null;
        resolve(value);
    }

    function confirmDialog(options = {}) {
        const dialog = document.getElementById("admin-confirm-dialog");
        const title = document.getElementById("admin-confirm-title");
        const message = document.getElementById("admin-confirm-message");
        const accept = dialog?.querySelector("[data-admin-confirm-accept]");
        const cancel = dialog?.querySelector("[data-admin-confirm-cancel]");
        const tone = options.tone && CONFIRM_TONES[options.tone] ? options.tone : "danger";

        if (!dialog || !title || !message || !accept || !cancel) {
            return Promise.resolve(false);
        }

        if (pendingConfirm) {
            closeDialog(dialog, "cancel");
            resolveConfirm(false);
        }

        title.textContent = options.title || "Подтвердить действие?";
        message.textContent = options.message || "Действие будет выполнено после подтверждения.";
        cancel.textContent = options.cancelLabel || "Отмена";
        accept.textContent = options.confirmLabel || "Подтвердить";
        accept.className = `btn btn-md ${CONFIRM_TONES[tone]}`;

        openDialog(dialog);

        if (tone === "danger") {
            cancel.focus();
        } else {
            accept.focus();
        }

        return new Promise((resolve) => {
            pendingConfirm = { resolve };
        });
    }

    function confirmOptionsFromElement(element) {
        return {
            title: element.dataset.adminConfirmTitle,
            message: element.dataset.adminConfirm || element.dataset.adminConfirmMessage,
            confirmLabel: element.dataset.adminConfirmLabel,
            cancelLabel: element.dataset.adminConfirmCancelLabel,
            tone: element.dataset.adminConfirmTone,
        };
    }

    function initExistingToasts() {
        document.querySelectorAll("[data-admin-toast]").forEach((toast) => {
            const type = normalizeToastType(toast.dataset.adminToastType);
            scheduleToast(toast, undefined, type);
        });
    }

    document.addEventListener("click", async (event) => {
        const closeToastButton = event.target.closest("[data-admin-toast-close]");
        if (closeToastButton) {
            closeToast(closeToastButton.closest("[data-admin-toast]"));
            return;
        }

        const confirmCancelButton = event.target.closest("[data-admin-confirm-cancel]");
        if (confirmCancelButton) {
            closeDialog(confirmCancelButton.closest("dialog"), "cancel");
            return;
        }

        const confirmAcceptButton = event.target.closest("[data-admin-confirm-accept]");
        if (confirmAcceptButton) {
            closeDialog(confirmAcceptButton.closest("dialog"), "confirm");
            return;
        }

        const closeDialogButton = event.target.closest("[data-admin-dialog-close], .dlg [data-close]");
        if (closeDialogButton) {
            closeDialog(closeDialogButton.closest("dialog"));
            return;
        }

        const openDialogButton = event.target.closest("[data-admin-dialog-open], [data-open]");
        if (openDialogButton) {
            const dialogId = openDialogButton.dataset.adminDialogOpen || openDialogButton.dataset.open;
            if (dialogId) {
                event.preventDefault();
                openDialog(dialogId);
            }
            return;
        }

        const confirmTrigger = event.target.closest("[data-admin-confirm]");
        if (!confirmTrigger) return;

        if (confirmTrigger.tagName === "FORM") {
            return;
        }

        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const form = confirmTrigger.form;
        const isSubmitter = form && ["submit", "image"].includes(confirmTrigger.type || "");

        if (isSubmitter && typeof form.reportValidity === "function" && !form.reportValidity()) {
            return;
        }

        event.preventDefault();

        const confirmed = await confirmDialog(confirmOptionsFromElement(confirmTrigger));
        if (!confirmed) return;

        if (isSubmitter) {
            form.dataset.adminConfirmBypass = "1";
            form.requestSubmit(confirmTrigger);
            return;
        }

        if (confirmTrigger.href) {
            window.location.assign(confirmTrigger.href);
        }
    });

    document.addEventListener("submit", async (event) => {
        const form = event.target.closest("form[data-admin-confirm]");

        if (!form) return;

        if (form.dataset.adminConfirmBypass === "1") {
            delete form.dataset.adminConfirmBypass;
            return;
        }

        event.preventDefault();

        const confirmed = await confirmDialog(confirmOptionsFromElement(form));
        if (!confirmed) return;

        form.dataset.adminConfirmBypass = "1";
        form.requestSubmit();
    });

    document.addEventListener("cancel", (event) => {
        if (event.target?.id === "admin-confirm-dialog") {
            resolveConfirm(false);
        }
    }, true);

    document.addEventListener("close", (event) => {
        if (event.target?.id === "admin-confirm-dialog" && pendingConfirm) {
            resolveConfirm(event.target.returnValue === "confirm");
        }
    }, true);

    document.addEventListener("click", (event) => {
        const dialog = event.target;

        if (dialog?.matches?.("dialog.dlg")) {
            const rect = dialog.getBoundingClientRect();
            const isClickInside = (
                rect.top <= event.clientY &&
                event.clientY <= rect.bottom &&
                rect.left <= event.clientX &&
                event.clientX <= rect.right
            );

            if (isClickInside) {
                return;
            }

            if (dialog.id === "admin-confirm-dialog") {
                closeDialog(dialog, "cancel");
                resolveConfirm(false);
            } else {
                closeDialog(dialog);
            }
        }
    });

    document.addEventListener("DOMContentLoaded", initExistingToasts);

    window.addEventListener("admin:toast", (event) => {
        createToast(event.detail || {});
    });

    window.AdminUI = {
        ...(window.AdminUI || {}),
        toast: createToast,
        confirm: confirmDialog,
        openDialog,
        closeDialog,
    };
})();
