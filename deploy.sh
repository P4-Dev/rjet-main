#!/bin/bash

# ---- Colors ----
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
DIM='\033[2m'
BOLD='\033[1m'
RESET='\033[0m'
CHECK="${GREEN}●${RESET}"
UNCHECK="${DIM}○${RESET}"

# ---- Project defaults ----
IMAGE_NAME="my-app"
GHCR_REPO="ghcr.io/your-user/${IMAGE_NAME}"
DOCKERHUB_REPO="your-user/${IMAGE_NAME}"

TAG="latest"
TARGET="both"
DB_DRIVERS="mysql"
EXTRA_PECL="redis"
EXTRA_EXT=""
IMAGE_SOURCE=$(git remote get-url origin 2>/dev/null | sed 's/git@github.com:/https:\/\/github.com\//' | sed 's/\.git$//')

# ---- Default extension registry (overridden by docker.conf) ----
AVAILABLE_DB=("mysql:MySQL / MariaDB" "pgsql:PostgreSQL" "sqlite:SQLite")
AVAILABLE_PECL=("redis:Redis cache driver" "mongodb:MongoDB driver" "imagick:Image manipulation" "memcached:Memcached cache driver")
AVAILABLE_EXT=("gmp:GNU Multiple Precision" "soap:SOAP web services" "sockets:Socket programming" "calendar:Calendar functions")

# ---- Load config file if exists ----
CONF_FILE="./docker.conf"
if [[ -f "$CONF_FILE" ]]; then
    source "$CONF_FILE"
fi

# ---- Parse registry format "name:description" into parallel arrays ----
parse_registry() {
    local -n _registry=$1
    local -n _names=$2
    local -n _descs=$3
    _names=()
    _descs=()
    for entry in "${_registry[@]}"; do
        _names+=("${entry%%:*}")
        _descs+=("${entry#*:}")
    done
}

parse_registry AVAILABLE_DB   DB_NAMES   DB_DESCS
parse_registry AVAILABLE_PECL PECL_NAMES PECL_DESCS
parse_registry AVAILABLE_EXT  EXT_NAMES  EXT_DESCS

# ---- Cleanup on exit ----
cleanup() {
    tput cnorm 2>/dev/null || true
}
trap cleanup EXIT

# ---- Interactive multi-select ----
multiselect() {
    local title="$1"
    local hint="$2"
    local -n _options=$3
    local -n _descriptions=$4
    local selected_str="$5"
    local -n _result=$6

    local count=${#_options[@]}
    local cursor=0
    local selected=()
    local lines_drawn=0

    for ((i=0; i<count; i++)); do
        if echo " $selected_str " | grep -q " ${_options[$i]} "; then
            selected+=("true")
        else
            selected+=("false")
        fi
    done

    tput civis 2>/dev/null || true

    draw() {
        if [[ $lines_drawn -gt 0 ]]; then
            for ((i=0; i<lines_drawn; i++)); do
                tput cuu1 2>/dev/null
                tput el 2>/dev/null
            done
        fi
        lines_drawn=0

        echo -e "  ${CYAN}${title}${RESET}"
        ((lines_drawn++))
        echo -e "  ${DIM}${hint}${RESET}"
        ((lines_drawn++))
        echo ""
        ((lines_drawn++))

        for ((i=0; i<count; i++)); do
            local marker="$UNCHECK"
            local name="${_options[$i]}"
            local desc="${_descriptions[$i]}"

            if [[ "${selected[$i]}" == "true" ]]; then
                marker="$CHECK"
                name="${GREEN}${name}${RESET}"
            fi

            if [[ $i -eq $cursor ]]; then
                echo -e "  ${BOLD}▸${RESET} ${marker}  ${name}  ${DIM}${desc}${RESET}"
            else
                echo -e "    ${marker}  ${name}  ${DIM}${desc}${RESET}"
            fi
            ((lines_drawn++))
        done
    }

    draw

    while true; do
        IFS= read -rsn1 key

        if [[ -z "$key" ]]; then
            break
        elif [[ "$key" == " " ]]; then
            if [[ "${selected[$cursor]}" == "true" ]]; then
                selected[$cursor]="false"
            else
                selected[$cursor]="true"
            fi
            draw
        elif [[ "$key" == $'\x1b' ]]; then
            read -rsn1 -t 0.1 seq1 || true
            read -rsn1 -t 0.1 seq2 || true
            if [[ "$seq1" == "[" ]]; then
                if [[ "$seq2" == "A" && $cursor -gt 0 ]]; then
                    cursor=$((cursor - 1))
                    draw
                elif [[ "$seq2" == "B" && $cursor -lt $((count - 1)) ]]; then
                    cursor=$((cursor + 1))
                    draw
                fi
            fi
        fi
    done

    tput cnorm 2>/dev/null || true

    _result=""
    for ((i=0; i<count; i++)); do
        if [[ "${selected[$i]}" == "true" ]]; then
            _result="${_result} ${_options[$i]}"
        fi
    done
    _result=$(echo "$_result" | xargs)
}

# ---- Single select ----
singleselect() {
    local title="$1"
    local hint="$2"
    local -n _soptions=$3
    local -n _sdescriptions=$4
    local cursor="$5"
    local -n _sresult=$6

    local count=${#_soptions[@]}
    local lines_drawn=0

    tput civis 2>/dev/null || true

    draw_s() {
        if [[ $lines_drawn -gt 0 ]]; then
            for ((i=0; i<lines_drawn; i++)); do
                tput cuu1 2>/dev/null
                tput el 2>/dev/null
            done
        fi
        lines_drawn=0

        echo -e "  ${CYAN}${title}${RESET}"
        ((lines_drawn++))
        echo -e "  ${DIM}${hint}${RESET}"
        ((lines_drawn++))
        echo ""
        ((lines_drawn++))

        for ((i=0; i<count; i++)); do
            local name="${_soptions[$i]}"
            local desc="${_sdescriptions[$i]}"

            if [[ $i -eq $cursor ]]; then
                echo -e "  ${BOLD}▸${RESET} ${GREEN}${name}${RESET}  ${DIM}${desc}${RESET}"
            else
                echo -e "    ${name}  ${DIM}${desc}${RESET}"
            fi
            ((lines_drawn++))
        done
    }

    draw_s

    while true; do
        IFS= read -rsn1 key

        if [[ -z "$key" ]]; then
            break
        elif [[ "$key" == $'\x1b' ]]; then
            read -rsn1 -t 0.1 seq1 || true
            read -rsn1 -t 0.1 seq2 || true
            if [[ "$seq1" == "[" ]]; then
                if [[ "$seq2" == "A" && $cursor -gt 0 ]]; then
                    cursor=$((cursor - 1))
                    draw_s
                elif [[ "$seq2" == "B" && $cursor -lt $((count - 1)) ]]; then
                    cursor=$((cursor + 1))
                    draw_s
                fi
            fi
        fi
    done

    tput cnorm 2>/dev/null || true
    _sresult="${_soptions[$cursor]}"
}

# ---- Interactive mode ----
interactive_mode() {
    echo ""
    echo -e "  ${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "  ${BOLD}  Deploy Wizard ${DIM}— ${IMAGE_NAME}${RESET}"
    echo -e "  ${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo ""

    # Step 1: Tag
    echo -e "  ${CYAN}Image Tag${RESET}"
    echo -e "  ${DIM}Press Enter for '${TAG}' or type a version (e.g. v1.0.0)${RESET}"
    echo ""
    echo -en "  Tag [${GREEN}${TAG}${RESET}]: "
    read -r input
    TAG="${input:-$TAG}"
    echo ""

    # Step 2: Database drivers
    multiselect "Database Drivers" \
        "↑↓ Navigate  •  Space Toggle  •  Enter Confirm" \
        DB_NAMES DB_DESCS "$DB_DRIVERS" DB_DRIVERS
    echo ""

    # Step 3: PECL extensions
    multiselect "PECL Extensions" \
        "↑↓ Navigate  •  Space Toggle  •  Enter Confirm" \
        PECL_NAMES PECL_DESCS "$EXTRA_PECL" EXTRA_PECL
    echo ""

    # Step 4: Extra PHP extensions
    multiselect "PHP Extensions" \
        "↑↓ Navigate  •  Space Toggle  •  Enter Confirm" \
        EXT_NAMES EXT_DESCS "$EXTRA_EXT" EXTRA_EXT
    echo ""

    # Step 5: Push target
    local TARGET_OPTIONS=("both" "ghcr" "dockerhub" "none")
    local TARGET_DESCS=("GHCR + Docker Hub" "GitHub Container Registry only" "Docker Hub only" "Build only, no push")
    singleselect "Push Destination" \
        "↑↓ Navigate  •  Enter Select" \
        TARGET_OPTIONS TARGET_DESCS 0 TARGET
    echo ""

    # Summary
    echo -e "  ${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "  ${BOLD}  Build Summary${RESET}"
    echo -e "  ${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "  Image:      ${GREEN}${IMAGE_NAME}:${TAG}${RESET}"
    echo -e "  DB Drivers: ${GREEN}${DB_DRIVERS:-none}${RESET}"
    echo -e "  PECL:       ${GREEN}${EXTRA_PECL:-none}${RESET}"
    echo -e "  Extensions: ${GREEN}${EXTRA_EXT:-none}${RESET}"
    echo -e "  Push to:    ${GREEN}${TARGET}${RESET}"
    echo -e "  ${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo ""
    echo -en "  Proceed? [${GREEN}Y${RESET}/n]: "
    read -r confirm
    if [[ "$confirm" == "n" || "$confirm" == "N" ]]; then
        echo "  Aborted."
        exit 0
    fi
    echo ""
}

# ---- Parse CLI arguments ----
HAS_ARGS=false
INTERACTIVE=""
while [[ $# -gt 0 ]]; do
    HAS_ARGS=true
    case "$1" in
        --ghcr)      TARGET="ghcr" ;;
        --dockerhub) TARGET="dockerhub" ;;
        --both)      TARGET="both" ;;
        --tag)       TAG="$2"; shift ;;
        --db)        DB_DRIVERS="$2"; shift ;;
        --pecl)      EXTRA_PECL="$2"; shift ;;
        --ext)       EXTRA_EXT="$2"; shift ;;
        --interactive|-i) INTERACTIVE=true ;;
        --help|-h)
            echo "Usage: ./deploy.sh [options]"
            echo ""
            echo "Without arguments: interactive wizard mode"
            echo ""
            echo "Options:"
            echo "  --tag <version>    Image tag (default: latest)"
            echo "  --db <drivers>     Database drivers (default: mysql)"
            echo "                     Available: ${DB_NAMES[*]}"
            echo "  --pecl <exts>      PECL extensions (default: redis)"
            echo "                     Available: ${PECL_NAMES[*]}"
            echo "  --ext <exts>       PHP extensions (default: none)"
            echo "                     Available: ${EXT_NAMES[*]}"
            echo "  --ghcr             Push only to GHCR"
            echo "  --dockerhub        Push only to Docker Hub"
            echo "  --both             Push to both (default)"
            echo "  -i, --interactive  Force interactive mode"
            echo "  -h, --help         Show this help"
            echo ""
            echo "Config file: docker.conf (auto-loaded if present)"
            echo ""
            echo "Examples:"
            echo "  ./deploy.sh                                # interactive wizard"
            echo "  ./deploy.sh --db pgsql                     # postgres only"
            echo "  ./deploy.sh --db 'mysql pgsql'             # mysql + postgres"
            echo "  ./deploy.sh --pecl 'redis mongodb'         # redis + mongodb"
            echo "  ./deploy.sh --tag v1.0.0 --db pgsql --ghcr"
            exit 0
            ;;
        *) echo "Unknown option: $1. Use --help for usage."; exit 1 ;;
    esac
    shift
done

# Enter interactive mode if no args or --interactive
if [[ "$HAS_ARGS" == false || "$INTERACTIVE" == true ]]; then
    interactive_mode
fi

# ---- Build ----
set -e

echo -e "  ${BOLD}=> Building image ${GREEN}${IMAGE_NAME}:${TAG}${RESET}..."
echo -e "     DB_DRIVERS: ${DB_DRIVERS:-none}"
echo -e "     EXTRA_PECL: ${EXTRA_PECL:-none}"
echo -e "     EXTRA_EXT:  ${EXTRA_EXT:-none}"
echo ""

docker build -t "${IMAGE_NAME}:${TAG}" \
    --build-arg IMAGE_SOURCE="${IMAGE_SOURCE}" \
    --build-arg DB_DRIVERS="${DB_DRIVERS}" \
    --build-arg EXTRA_PECL="${EXTRA_PECL}" \
    --build-arg EXTRA_EXT="${EXTRA_EXT}" \
    -f Dockerfile .

SIZE=$(docker images "${IMAGE_NAME}:${TAG}" --format '{{.Size}}')
echo ""

case "${TARGET}" in
    ghcr)
        echo -e "  => Pushing to ${CYAN}GHCR${RESET}..."
        docker tag "${IMAGE_NAME}:${TAG}" "${GHCR_REPO}:${TAG}"
        docker push "${GHCR_REPO}:${TAG}"
        echo -e "  ${GREEN}=> Pushed to ${GHCR_REPO}:${TAG} — Image size: ${BOLD}${SIZE}${RESET}"
        ;;
    dockerhub)
        echo -e "  => Pushing to ${CYAN}Docker Hub${RESET}..."
        docker tag "${IMAGE_NAME}:${TAG}" "${DOCKERHUB_REPO}:${TAG}"
        docker push "${DOCKERHUB_REPO}:${TAG}"
        echo -e "  ${GREEN}=> Pushed to ${DOCKERHUB_REPO}:${TAG} — Image size: ${BOLD}${SIZE}${RESET}"
        ;;
    both)
        echo -e "  => Pushing to ${CYAN}GHCR${RESET}..."
        docker tag "${IMAGE_NAME}:${TAG}" "${GHCR_REPO}:${TAG}"
        docker push "${GHCR_REPO}:${TAG}"
        echo -e "  ${GREEN}=> Pushed to ${GHCR_REPO}:${TAG}${RESET}"
        echo -e "  => Pushing to ${CYAN}Docker Hub${RESET}..."
        docker tag "${IMAGE_NAME}:${TAG}" "${DOCKERHUB_REPO}:${TAG}"
        docker push "${DOCKERHUB_REPO}:${TAG}"
        echo -e "  ${GREEN}=> Pushed to ${DOCKERHUB_REPO}:${TAG} — Image size: ${BOLD}${SIZE}${RESET}"
        ;;
    none)
        echo -e "  ${GREEN}=> Build complete! — Image size: ${BOLD}${SIZE}${RESET}"
        ;;
esac
