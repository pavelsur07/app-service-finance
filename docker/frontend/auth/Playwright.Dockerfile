FROM mcr.microsoft.com/playwright:v1.56.1-noble
# Test-only CA trust in Chromium's NSS database; never ignore HTTPS errors.
RUN apt-get update && apt-get install -y --no-install-recommends libnss3-tools openssl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
