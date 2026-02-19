import os
import datetime

Import("env")

# Configuration
VERSION_FILE = "version.txt"
MAJOR = "1"
MINOR = "0"

# 1. Read the current build/patch number
build_no = 0
if os.path.exists(VERSION_FILE):
    with open(VERSION_FILE, "r") as f:
        try:
            build_no = int(f.read().strip())
        except:
            build_no = 0

# 2. Increment the build number
build_no += 1

# 3. Write the new number back to the file
with open(VERSION_FILE, "w") as f:
    f.write(str(build_no))

# 4. Construct the Full Version String
# Format: v1.0.42 (2024-05-20)
full_version = f"v{MAJOR}.{MINOR}.{build_no}"
date_str = datetime.datetime.now().strftime("%Y-%m-%d")

print(f"--- AUTO-VERSIONING: {full_version} ---")

# 5. Inject variables into the C++ compiler
# We use escaped quotes so they arrive as C-strings: "v1.0.42"
env.Append(
    CPPDEFINES=[
        ("APP_VERSION", f'\\"{full_version}\\"'),
        ("BUILD_DATE", f'\\"{date_str}\\"'),
        ("BUILD_NUMBER", build_no),
    ]
)
