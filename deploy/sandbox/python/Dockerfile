FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

RUN useradd --create-home --uid 10001 sandbox \
    && pip install --no-cache-dir requests==2.32.3

WORKDIR /workspace
COPY runner.py /opt/sandbox_runner/runner.py

USER sandbox

ENTRYPOINT ["python", "/opt/sandbox_runner/runner.py"]
