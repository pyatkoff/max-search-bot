FROM mcr.microsoft.com/playwright:v1.55.0-noble@sha256:ffc33305f7b4b04057ae4a0caa70aad4fde87454fb403a1a22e7f931707dfcf9

ARG QA_RECIPE_SHA
LABEL org.opencontainers.image.source="https://github.com/pyatkoff/max-search-bot"
LABEL org.opencontainers.image.description="Prepared native browser QA; no application or production data"
LABEL io.anytour.browser-qa.recipe="${QA_RECIPE_SHA}"

# The official image already contains Node22, all three pinned browsers and
# their OS libraries/fonts. PHP is installed once here, never in test jobs.
RUN apt-get update && apt-get install -y --no-install-recommends \
      php8.3-cli php8.3-mbstring php8.3-sqlite3 php8.3-xml \
      php8.3-curl php8.3-mysql python3 \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/manager2-qa
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --fund=false && rm -rf /root/.npm
COPY run verify-runtime.js preflight.js ./
RUN chmod 755 run \
    && node verify-runtime.js --build-manifest "$QA_RECIPE_SHA"
WORKDIR /tmp
