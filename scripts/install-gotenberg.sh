#!/usr/bin/env bash
# Start Gotenberg (Chromium URL→PDF) and write Laravel env keys.
# Same script on the laptop and on the cloud VM (dev/test/prod share one box).
#
#   ./scripts/install-gotenberg.sh                 # local: Docker + Vite on the host
#   ./scripts/install-gotenberg.sh --install-runtime
#   ./scripts/install-gotenberg.sh --cloud --env-file /path/to/backend/.env
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/backend/.env"
MODE="local"
INSTALL_RUNTIME=0
CONTAINER_NAME="flow-gotenberg"
IMAGE="gotenberg/gotenberg:8"
PORT="3000"
GOTENBERG_URL="http://127.0.0.1:${PORT}"

usage() {
  cat <<'EOF'
Usage: scripts/install-gotenberg.sh [options]

Starts a loopback-only Gotenberg container and writes GOTENBERG_URL into Laravel .env.

Options:
  --local                 PHP + Vite on this machine (default). Also sets
                          PRINT_PAGE_BASE_URL=http://host.docker.internal:5173
  --cloud                 PHP on the VM; Chromium opens FRONTEND_URL.
                          Comments out PRINT_PAGE_BASE_URL.
  --env-file PATH         Laravel .env to patch (default: backend/.env)
  --install-runtime       macOS: brew install docker + colima, then colima start
  -h, --help              Show this help

Cloud (run once for the container, then once per app .env):
  ./scripts/install-gotenberg.sh --cloud --env-file ~/public_html/flow-dev/.env
  ./scripts/install-gotenberg.sh --cloud --env-file ~/public_html/flow-test/.env
  ./scripts/install-gotenberg.sh --cloud --env-file ~/public_html/flow-prod/.env
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --local) MODE="local"; shift ;;
    --cloud) MODE="cloud"; shift ;;
    --env-file)
      ENV_FILE="${2:-}"
      if [[ -z "$ENV_FILE" ]]; then
        echo "error: --env-file needs a path" >&2
        exit 2
      fi
      shift 2
      ;;
    --install-runtime) INSTALL_RUNTIME=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *)
      echo "error: unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

find_docker() {
  local candidate
  for candidate in \
    docker \
    /usr/local/bin/docker \
    /opt/homebrew/bin/docker \
    "$HOME/.docker/bin/docker" \
    /Applications/Docker.app/Contents/Resources/bin/docker
  do
    if [[ "$candidate" == "docker" ]] && command -v docker >/dev/null 2>&1; then
      command -v docker
      return 0
    fi
    if [[ -x "$candidate" ]]; then
      echo "$candidate"
      return 0
    fi
  done
  return 1
}

docker_ready() {
  local bin="$1"
  "$bin" info >/dev/null 2>&1
}

install_runtime_macos() {
  if ! command -v brew >/dev/null 2>&1; then
    echo "error: Homebrew is not installed. Install it from https://brew.sh then re-run." >&2
    exit 1
  fi
  echo "Installing Docker CLI + Colima via Homebrew…"
  brew install docker colima
  if ! colima status >/dev/null 2>&1; then
    echo "Starting Colima (2 CPU, 4 GB)…"
    colima start --cpu 2 --memory 4
  else
    echo "Colima is already running."
  fi
}

upsert_env() {
  local file="$1" key="$2" value="$3"
  python3 - "$file" "$key" "$value" <<'PY'
import pathlib, re, sys
path = pathlib.Path(sys.argv[1])
key, value = sys.argv[2], sys.argv[3]
text = path.read_text() if path.exists() else ""
line = f"{key}={value}"
pattern = re.compile(rf"^[ \t]*#?[ \t]*{re.escape(key)}=.*$", re.M)
if pattern.search(text):
    text = pattern.sub(line, text, count=1)
else:
    if text and not text.endswith("\n"):
        text += "\n"
    text += f"\n{line}\n"
path.write_text(text)
PY
}

comment_env() {
  local file="$1" key="$2"
  python3 - "$file" "$key" <<'PY'
import pathlib, re, sys
path = pathlib.Path(sys.argv[1])
key = sys.argv[2]
if not path.exists():
    sys.exit(0)
text = path.read_text()
pattern = re.compile(rf"^[ \t]*#?[ \t]*{re.escape(key)}=.*$", re.M)

def repl(match):
    line = match.group(0).strip()
    if line.startswith("#"):
        return match.group(0)
    return f"# {line}"

path.write_text(pattern.sub(repl, text, count=1))
PY
}

wait_healthy() {
  local url="$1" tries=30
  local i
  for i in $(seq 1 "$tries"); do
    if curl -fsS --max-time 2 "${url}/health" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

if [[ "$INSTALL_RUNTIME" -eq 1 ]]; then
  case "$(uname -s)" in
    Darwin) install_runtime_macos ;;
    *)
      echo "error: --install-runtime is only automated on macOS." >&2
      echo "On the cloud VM install Docker, then re-run without that flag." >&2
      exit 1
      ;;
  esac
fi

DOCKER_BIN="$(find_docker || true)"
if [[ -z "$DOCKER_BIN" ]]; then
  echo "Docker is not installed." >&2
  if [[ "$(uname -s)" == "Darwin" ]]; then
    echo "On this Mac, install the runtime then continue:" >&2
    echo "  $0 --install-runtime" >&2
    echo "  $0 --local" >&2
  else
    echo "Install Docker on this host, then re-run:" >&2
    echo "  $0 --cloud --env-file /path/to/backend/.env" >&2
  fi
  exit 1
fi

if ! docker_ready "$DOCKER_BIN"; then
  if command -v colima >/dev/null 2>&1; then
    echo "Docker daemon is down. Starting Colima…"
    colima start
  elif [[ -d /Applications/Docker.app ]]; then
    echo "Docker daemon is down. Opening Docker Desktop — wait until it is running, then re-run." >&2
    open -a Docker
    exit 1
  else
    echo "error: Docker CLI is present but the daemon is not running." >&2
    exit 1
  fi
  if ! docker_ready "$DOCKER_BIN"; then
    echo "error: Docker daemon still not ready." >&2
    exit 1
  fi
fi

if curl -fsS --max-time 2 "${GOTENBERG_URL}/health" >/dev/null 2>&1; then
  echo "Gotenberg already healthy at ${GOTENBERG_URL}"
else
  if "$DOCKER_BIN" inspect "$CONTAINER_NAME" >/dev/null 2>&1; then
    echo "Removing stopped container ${CONTAINER_NAME}…"
    "$DOCKER_BIN" rm -f "$CONTAINER_NAME" >/dev/null
  fi
  echo "Pulling ${IMAGE}…"
  "$DOCKER_BIN" pull "$IMAGE"
  echo "Starting ${CONTAINER_NAME} on 127.0.0.1:${PORT}…"
  "$DOCKER_BIN" run -d \
    --name "$CONTAINER_NAME" \
    --restart unless-stopped \
    --add-host=host.docker.internal:host-gateway \
    -p "127.0.0.1:${PORT}:3000" \
    "$IMAGE" \
    gotenberg --api-timeout=120s --libreoffice-disable-routes >/dev/null
  echo "Waiting for /health…"
  if ! wait_healthy "$GOTENBERG_URL"; then
    echo "error: Gotenberg did not become healthy. Logs:" >&2
    "$DOCKER_BIN" logs --tail 50 "$CONTAINER_NAME" >&2 || true
    exit 1
  fi
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "error: .env not found: $ENV_FILE" >&2
  exit 1
fi

upsert_env "$ENV_FILE" "GOTENBERG_URL" "$GOTENBERG_URL"
if [[ "$MODE" == "local" ]]; then
  upsert_env "$ENV_FILE" "PRINT_PAGE_BASE_URL" "http://host.docker.internal:5173"
else
  comment_env "$ENV_FILE" "PRINT_PAGE_BASE_URL"
fi

echo
echo "Gotenberg is running at ${GOTENBERG_URL}"
echo "Patched ${ENV_FILE} (${MODE})"
if [[ "$MODE" == "local" ]]; then
  echo "Chromium will open http://host.docker.internal:5173 (Vite on the host)."
  echo "Keep npm run dev running, then POST /api/print/{eventId}/overview-sheet"
else
  echo "Chromium will open FRONTEND_URL from that .env."
  echo "One container serves every app on this host. Repeat --env-file for each .env."
fi
echo "Reload Laravel config if it is cached: php artisan config:clear"
