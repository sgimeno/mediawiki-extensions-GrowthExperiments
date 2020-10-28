-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/GrowthExperiments/maintenance/growthexperiments_link_recommendations.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE growthexperiments_link_recommendations (
  gelr_revision INT NOT NULL,
  gelr_page INT NOT NULL,
  gelr_data TEXT NOT NULL,
  PRIMARY KEY(gelr_revision)
);

CREATE INDEX gelr_page ON growthexperiments_link_recommendations (gelr_page);
