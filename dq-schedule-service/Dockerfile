FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY dq_schedule_simple.py dq_rules_schedule.py ./

# SQLite DB lives in /data so it survives container rebuilds via volume mount
ENV DQ_DB_PATH=/data/dq_schedules.db
RUN mkdir -p /data
VOLUME ["/data"]

EXPOSE 8001

CMD ["python", "dq_schedule_simple.py"]
