import argparse
import hashlib
import json
import re
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
START = "<!-- catalog:start -->"
END = "<!-- catalog:end -->"
STATUSES = ("in-use", "recommended")
TYPES = {"desktop": "Program", "web": "Webtjeneste", "plugin": "Udvidelse"}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def nonempty(value):
    return isinstance(value, str) and bool(value.strip())


def is_url(value):
    if not nonempty(value) or any(char.isspace() or char in "<>" for char in value):
        return False
    parts = urlsplit(value)
    return parts.scheme == "https" and bool(parts.hostname)


def index(entries, label):
    require(isinstance(entries, list) and entries, f"{label} skal være en liste med poster.")
    indexed = {}
    for entry in entries:
        require(isinstance(entry, dict), f"Ugyldig post i {label}.")
        entry_id = entry.get("id")
        require(
            nonempty(entry_id) and re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", entry_id),
            f"Ugyldigt id i {label}: {entry_id!r}",
        )
        require(entry_id not in indexed, f"Dublet-id i {label}: {entry_id}")
        indexed[entry_id] = entry
    return indexed


def validate(data):
    require(
        isinstance(data, dict)
        and type(data.get("schemaVersion")) is int
        and data["schemaVersion"] == 1,
        "schemaVersion skal være 1.",
    )
    categories = index(data.get("categories"), "categories")
    entries = index(data.get("tools"), "tools")
    for category_id, category in categories.items():
        require(nonempty(category.get("label")), f"{category_id} mangler label.")
    for entry_id, entry in entries.items():
        for field in ("name", "description"):
            require(nonempty(entry.get(field)), f"{entry_id} mangler {field}.")
        require(is_url(entry.get("url")), f"{entry_id} skal have en gyldig HTTPS-URL.")
        require(entry.get("status") in STATUSES, f"{entry_id} har ugyldig status.")
        require(entry.get("type") in tuple(TYPES), f"{entry_id} har ugyldig type.")
        refs = entry.get("categoryIds")
        require(isinstance(refs, list) and refs, f"{entry_id} mangler categoryIds.")
        for ref in refs:
            require(
                nonempty(ref) and ref in categories,
                f"{entry_id} har en ukendt kategori: {ref!r}",
            )
        require(len(refs) == len(set(refs)), f"{entry_id} gentager en kategori.")
        if "license" in entry:
            require(nonempty(entry["license"]), f"{entry_id} har ugyldig license.")
        if "githubUrl" in entry:
            url = entry["githubUrl"]
            require(
                is_url(url) and urlsplit(url).netloc == "github.com",
                f"{entry_id} skal have en gyldig GitHub-URL.",
            )
        if "details" in entry or "detailsSourceUrl" in entry:
            require(nonempty(entry.get("details")), f"{entry_id} mangler details.")
            require(is_url(entry.get("detailsSourceUrl")), f"{entry_id} mangler en kilde til details.")
        if "logo" in entry:
            logo = entry["logo"]
            path = f"logos/{entry_id}.png"
            require(
                isinstance(logo, dict) and logo.get("path") == path and is_url(logo.get("sourceUrl")),
                f"{entry_id} har ugyldige logooplysninger.",
            )
            image = (ROOT / path).read_bytes()
            require(
                image.startswith(b"\x89PNG\r\n\x1a\n")
                and len(image) <= 512 * 1024
                and logo.get("sha256") == hashlib.sha256(image).hexdigest(),
                f"{entry_id} har en ugyldig PNG-fil eller en SHA-256, der ikke stemmer.",
            )
        for field, validator in (("hardware", nonempty), ("sourceUrls", is_url)):
            if field in entry:
                values = entry[field]
                require(
                    isinstance(values, list) and values and all(map(validator, values)),
                    f"{entry_id} har ugyldig {field}.",
                )
    return categories, entries.values()


def cell(value):
    return " ".join(value.split()).replace("|", r"\|")


def render_catalog(categories, entries):
    lines = ["## Katalog", ""]
    ordered = sorted(
        entries, key=lambda entry: (entry["status"] != "in-use", entry["name"].casefold())
    )
    for category_id, category in categories.items():
        lines.extend([
            f'### {category["label"]}',
            "",
            "| Værktøj | Type | Licens | Hvad bruger jeg det til? | Udstyr |",
            "| --- | --- | --- | --- | --- |",
        ])
        for entry in ordered:
            if category_id not in entry["categoryIds"]:
                continue
            name = cell(entry["name"]).replace("[", r"\[").replace("]", r"\]")
            description = cell(entry["description"])
            hardware = cell(", ".join(entry.get("hardware", ["—"])))
            license_text = cell(entry.get("license", "—"))
            if entry.get("license") == "Open source" and "githubUrl" in entry:
                license_text += f' ([GitHub](<{entry["githubUrl"]}>))'
            lines.append(
                f'| [{name}](<{entry["url"]}>) | '
                f'{TYPES[entry["type"]]} | {license_text} | '
                f'{description} | {hardware} |'
            )
        lines.append("")
    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description="Generér katalogsektionen i README.")
    parser.add_argument("--check", action="store_true", help="Kontrollér uden at skrive.")
    args = parser.parse_args()
    try:
        data = json.loads((ROOT / "software.json").read_text(encoding="utf-8"))
        categories, entries = validate(data)
        path = ROOT / "README.md"
        original = path.read_text(encoding="utf-8")
        lines = original.splitlines(keepends=True)
        starts = [i for i, line in enumerate(lines) if line == START + "\n"]
        ends = [i for i, line in enumerate(lines) if line.rstrip("\n") == END]
        require(
            len(starts) == len(ends) == 1 and starts[0] < ends[0],
            "README skal indeholde begge katalogmarkører én gang i korrekt rækkefølge.",
        )
        before = "".join(lines[:starts[0] + 1])
        after = "".join(lines[ends[0]:])
        updated = f"{before}\n{render_catalog(categories, entries)}\n{after}"
        if args.check:
            require(updated == original, "README er forældet. Kør python scripts/catalog.py.")
        elif updated != original:
            path.write_text(updated, encoding="utf-8", newline="\n")
    except (ValueError, OSError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    main()
