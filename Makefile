.PHONY: web2

web2:
	php -S localhost:8005 & \
	sleep 1 && open http://localhost:8005/

up:
	docker-compose up -d

down:
	docker-compose down

build:
	docker-compose build --no-cache