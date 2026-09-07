from setuptools import find_packages, setup

try:
    with open("allianceauth-wormholesystems/README.md", "r", encoding="utf-8") as fh:
        long_description = fh.read()
except Exception:
    long_description = "Wormhole Systems single sign-on & sidebar app for Alliance Auth"

setup(
    name="allianceauth-wormholesystems",
    version="0.0.5",
    author="Wormhole Systems",
    description="Wormhole Systems single sign-on & sidebar app for Alliance Auth",
    long_description=long_description,
    long_description_content_type="text/markdown",
    url="https://github.com/Thrainkrilleve/wormies",
    package_dir={"": "allianceauth-wormholesystems"},
    packages=find_packages(where="allianceauth-wormholesystems"),
    include_package_data=True,
    classifiers=[
        "Environment :: Web Environment",
        "Framework :: Django",
        "Intended Audience :: Developers",
        "License :: OSI Approved :: MIT License",
        "Operating System :: OS Independent",
        "Programming Language :: Python :: 3",
        "Topic :: Internet :: WWW/HTTP",
    ],
    python_requires=">=3.10",
    install_requires=[
        "allianceauth>=4.0.0",
    ],
)
