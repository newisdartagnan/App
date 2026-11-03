-- Créée une seule fois, au premier démarrage du volume de la base : la base
-- dédiée aux tests, que phpunit.xml impose et que tests/TestCase.php exige.
CREATE DATABASE doc_and_i_test OWNER doc_and_i;
