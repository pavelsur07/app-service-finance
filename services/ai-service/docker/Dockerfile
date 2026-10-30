# ai-service: multi-stage, non-root, закреплённые базовые образы (services/AGENTS.md §4).
# Цели: runtime (по умолчанию) — сервис; dev — гейты (ruff, mypy, pytest, evals).

FROM ghcr.io/astral-sh/uv:0.13.3 AS uv

FROM python:3.12.16-slim-trixie AS base
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PYTHON_DOWNLOADS=never \
    UV_PROJECT_ENVIRONMENT=/opt/venv
COPY --from=uv /uv /usr/local/bin/uv
WORKDIR /app

FROM base AS build
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-dev --no-install-project
COPY src ./src
COPY config ./config
COPY migrations ./migrations
RUN uv sync --frozen --no-dev --no-editable

FROM base AS dev
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-install-project
ENV PATH=/opt/venv/bin:$PATH
# Исходники монтируются томом (docker-compose.yml, профиль services).

FROM python:3.12.16-slim-trixie AS runtime
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PATH=/opt/venv/bin:$PATH
RUN useradd --system --uid 10001 --no-create-home app
COPY --from=build /opt/venv /opt/venv
COPY --from=build /app/config /app/config
COPY --from=build /app/migrations /app/migrations
WORKDIR /app
ENV AI_SERVICE_ROUTES_FILE=/app/config/routes.yaml AI_SERVICE_MIGRATIONS_DIR=/app/migrations
USER app
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=2).status == 200 else 1)"
ENTRYPOINT ["python", "-m", "ai_service"]
CMD ["api"]
