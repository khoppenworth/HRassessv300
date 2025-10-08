.PHONY: run lint seed-admin

run:
	php -S localhost:8080 -t .

lint:
	@find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l > /dev/null
	@echo "All PHP files linted successfully."

seed-admin:
	php scripts/seed_admin.php
