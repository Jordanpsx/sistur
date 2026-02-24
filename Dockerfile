FROM python:3.11-slim
WORKDIR /app
# Instala o básico para não quebrar o container
RUN pip install --no-cache-dir flask sqlalchemy mysql-connector-python
# Copia tudo para o container
COPY . .
EXPOSE 5000
# Mantém o container ativo mesmo sem código pesado
CMD ["python", "-m", "http.server", "5000"]