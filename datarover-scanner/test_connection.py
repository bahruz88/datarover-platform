"""
iomete Connection Test Script

Usage:
  
"""

from connectors.iomete import IometeConnector


# ═══════════════════════════════════════════════════════════════
# KONFİQURASİYA — öz dəyərlərini yaz
# ═══════════════════════════════════════════════════════════════

HOST       = "your-account.iomete.com"    # iomete host
PORT       = 443                           # default 443
USERNAME   = "your-username"
PASSWORD   = "your-personal-access-token"  # iomete PAT
DATABASE   = "default"                     # schema adı
LAKEHOUSE  = None                          # məs: "my-lakehouse"  (yoxdursa None)
DATA_PLANE = None                          # məs: "eu-central-1"  (yoxdursa None)


def main():
    conn = IometeConnector(
        host=HOST,
        port=PORT,
        username=USERNAME,
        password=PASSWORD,
        database=DATABASE,
        lakehouse=LAKEHOUSE,
        data_plane=DATA_PLANE,
    )

    print("=" * 60)
    print("1) Bağlantı testi")
    print("=" * 60)
    result = conn.test_connection()
    print(result)
    if not result.get("success"):
        print("\nBağlantı uğursuz oldu — yuxarıdakı mesaja bax.")
        return

    print("\n" + "=" * 60)
    print("2) Schema siyahısı")
    print("=" * 60)
    schemas = conn.list_schemas()
    for s in schemas:
        print(f"  - {s['name']}  ({s['table_count']} cədvəl)")

    print("\n" + "=" * 60)
    print(f"3) '{DATABASE}' daxilində cədvəllər")
    print("=" * 60)
    tables = conn.list_tables(schema=DATABASE)
    for t in tables[:10]:
        print(f"  - {t['schema_name']}.{t['table_name']}  "
              f"type={t['table_type']}  rows={t['row_count']}  size={t['size_mb']}MB")
    if len(tables) > 10:
        print(f"  ... və daha {len(tables) - 10} cədvəl")

    if tables:
        first = tables[0]
        print("\n" + "=" * 60)
        print(f"4) Sütunlar: {first['schema_name']}.{first['table_name']}")
        print("=" * 60)
        cols = conn.get_columns(schema=first["schema_name"], table=first["table_name"])
        for c in cols:
            print(f"  {c['position']:>2}. {c['column_name']:<30} {c['full_type']}")


if __name__ == "__main__":
    main()
