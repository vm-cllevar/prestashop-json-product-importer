#!/usr/bin/env python3
"""Build a PrestaShop-installable archive using only module files."""
from pathlib import Path
import re
from zipfile import ZipFile, ZIP_DEFLATED
root = Path(__file__).resolve().parent.parent
module = root / 'jsonproductpreview'
version = re.search(r"\$this->version='([^']+)'", (module / 'jsonproductpreview.php').read_text()).group(1)
output = root / 'dist' / f'jsonproductpreview-{version}.zip'
output.parent.mkdir(exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for file in sorted(module.rglob('*')):
        if file.is_file() and file.suffix in ('.php', '.md', '.json'):
            archive.write(file, file.relative_to(root))
print(output)
