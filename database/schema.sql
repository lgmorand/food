-- Food - schéma SQLite

CREATE TABLE IF NOT EXISTS households (
    id          TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            TEXT PRIMARY KEY,
    household_id  TEXT NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name  TEXT NOT NULL,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ingredients (
    id           TEXT PRIMARY KEY,
    household_id TEXT NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    name         TEXT NOT NULL,
    name_key     TEXT NOT NULL,
    default_unit TEXT,
    category     TEXT NOT NULL DEFAULT 'autre',
    created_at   TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ingredients_household_key
    ON ingredients(household_id, name_key);

CREATE TABLE IF NOT EXISTS recipes (
    id           TEXT PRIMARY KEY,
    household_id TEXT NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    name         TEXT NOT NULL,
    name_key     TEXT NOT NULL,
    photo_url    TEXT,
    is_active    INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_recipes_household_key
    ON recipes(household_id, name_key);

CREATE TABLE IF NOT EXISTS recipe_tags (
    recipe_id TEXT NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    tag       TEXT NOT NULL,
    PRIMARY KEY (recipe_id, tag)
);

CREATE TABLE IF NOT EXISTS recipe_ingredients (
    recipe_id     TEXT NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    ingredient_id TEXT NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    quantity      REAL,
    unit          TEXT,
    position      INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (recipe_id, ingredient_id)
);

CREATE TABLE IF NOT EXISTS menus (
    id           TEXT PRIMARY KEY,
    household_id TEXT NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    week_start   TEXT NOT NULL,
    size         INTEGER NOT NULL,
    status       TEXT NOT NULL DEFAULT 'draft',
    validated_at TEXT,
    created_at   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_menus_household_week ON menus(household_id, week_start);

CREATE TABLE IF NOT EXISTS menu_items (
    menu_id      TEXT NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
    position     INTEGER NOT NULL,
    recipe_id    TEXT REFERENCES recipes(id) ON DELETE SET NULL,
    recipe_name  TEXT NOT NULL,
    recipe_photo TEXT,
    locked       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (menu_id, position)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_menu_items_unique_recipe
    ON menu_items(menu_id, recipe_id);

CREATE TABLE IF NOT EXISTS shopping_lists (
    id           TEXT PRIMARY KEY,
    menu_id      TEXT NOT NULL UNIQUE REFERENCES menus(id) ON DELETE CASCADE,
    household_id TEXT NOT NULL REFERENCES households(id) ON DELETE CASCADE,
    created_at   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shopping_items (
    id             TEXT PRIMARY KEY,
    list_id        TEXT NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
    ingredient_id  TEXT,
    label          TEXT NOT NULL,
    category       TEXT NOT NULL DEFAULT 'autre',
    quantities     TEXT NOT NULL DEFAULT '[]',
    source_recipes TEXT NOT NULL DEFAULT '[]',
    checked        INTEGER NOT NULL DEFAULT 0,
    is_manual      INTEGER NOT NULL DEFAULT 0,
    position       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_shopping_items_list ON shopping_items(list_id);
