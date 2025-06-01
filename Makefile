.PHONY: web2

web2:
	php -S localhost:8004 & \
	sleep 1 && open http://localhost:8004/recorder.html