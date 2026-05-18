FROM python:3.12-slim

# Keeps Python from generating .pyc files and enables immediate log output
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

# curl is used in docker-compose health-wait scripts
RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Install pip dependencies first so this layer is cached unless requirements change
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# Защита от CRLF-окончаний строк, если клон был сделан на Windows
# с core.autocrlf=true до появления .gitattributes в репозитории
RUN sed -i 's/\r$//' entrypoint.sh

EXPOSE 8000

# entrypoint.sh runs migrations then exec's CMD
ENTRYPOINT ["bash", "entrypoint.sh"]
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
