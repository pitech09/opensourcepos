#!/usr/bin/env python3
"""
FIFO Batch Inventory Manager
=============================

A simple command-line inventory management system that tracks product batches
with FIFO (first-in, first-out) consumption and per-batch selling prices.

README / How to run
-------------------
Requirements:
    - Python 3.6+ (standard library only; sqlite3 is bundled with Python)

Setup:
    No installation needed. The SQLite database file (inventory.db) is created
    automatically in the current directory on first use.

Usage:
    python fifo_inventory.py add_purchase <product> <qty> <unit_cost> <unit_price>
        Add a new batch (purchase) for a product.
        Example:  python fifo_inventory.py add_purchase A 10 3 5
        (product A, quantity 10, unit cost 3, selling price 5)

    python fifo_inventory.py sell <product> <qty>
        Record a sale. Units are deducted from the OLDEST batch first (FIFO).
        A sale spanning multiple batches is split automatically, each part
        priced at its own batch's selling price.
        Example:  python fifo_inventory.py sell A 3

    python fifo_inventory.py stock
        Show all batches with remaining quantity, unit cost and selling price.

    python fifo_inventory.py sales_report
        Show every sale with its per-batch breakdown (units, revenue, cost).

    python fifo_inventory.py help
        Show usage summary.

Example session (matches the scenario in the requirements):
    $ python fifo_inventory.py add_purchase A 2 2 5     # old batch: 2 units @ M5
    $ python fifo_inventory.py add_purchase A 10 6 7    # new batch: 10 units @ M7
    $ python fifo_inventory.py sell A 3
      -> 2 units from old batch @ M5 + 1 unit from new batch @ M7
    $ python fifo_inventory.py stock
    $ python fifo_inventory.py sales_report
"""

import sqlite3
import sys
import os
from datetime import datetime

DB_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "inventory.db")


class InventoryManager:
    """Manages product batches, FIFO sales, and reporting on a SQLite DB."""

    def __init__(self, db_path: str = DB_FILE):
        self.db_path = db_path
        self.conn = sqlite3.connect(db_path)
        self.conn.execute("PRAGMA foreign_keys = ON")
        self._create_tables()

    # ------------------------------------------------------------------ setup

    def _create_tables(self):
        """Create the required SQLite tables if they don't exist."""
        cur = self.conn.cursor()
        # One row per purchase (batch) of a product.
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS batches (
                batch_id     INTEGER PRIMARY KEY AUTOINCREMENT,
                product      TEXT    NOT NULL,
                quantity     REAL    NOT NULL,           -- original quantity
                remaining    REAL    NOT NULL,           -- units still in stock
                unit_cost    REAL    NOT NULL,           -- buying cost per unit
                unit_price   REAL    NOT NULL,           -- selling price per unit
                created_at   TEXT    NOT NULL            -- ISO timestamp
            )
            """
        )
        # One row per sale transaction.
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS sales (
                sale_id      INTEGER PRIMARY KEY AUTOINCREMENT,
                product      TEXT    NOT NULL,
                quantity     REAL    NOT NULL,           -- total units sold
                revenue      REAL    NOT NULL,           -- total revenue
                cost         REAL    NOT NULL,           -- total cost of goods
                sold_at      TEXT    NOT NULL            -- ISO timestamp
            )
            """
        )
        # Which batch each sold unit (part) came from — batch breakdown per sale.
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS sale_batches (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                sale_id      INTEGER NOT NULL REFERENCES sales(sale_id),
                batch_id     INTEGER NOT NULL REFERENCES batches(batch_id),
                quantity     REAL    NOT NULL,           -- units taken from this batch
                unit_price   REAL    NOT NULL,           -- batch's selling price
                unit_cost    REAL    NOT NULL,           -- batch's unit cost
                revenue      REAL    NOT NULL,           -- quantity * unit_price
                cost         REAL    NOT NULL            -- quantity * unit_cost
            )
            """
        )
        self.conn.commit()

    # -------------------------------------------------------------- purchases

    def add_purchase(self, product: str, quantity: float, unit_cost: float,
                     unit_price: float):
        """Add a new batch (purchase) for a product."""
        if quantity <= 0:
            raise ValueError("Quantity must be greater than 0.")
        if unit_cost < 0 or unit_price < 0:
            raise ValueError("Cost and price cannot be negative.")

        now = datetime.now().isoformat(timespec="seconds")
        cur = self.conn.execute(
            "INSERT INTO batches (product, quantity, remaining, unit_cost, "
            "unit_price, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            (product, quantity, quantity, unit_cost, unit_price, now),
        )
        self.conn.commit()
        print(f"OK: Added batch #{cur.lastrowid} for '{product}': "
              f"{quantity:g} units, cost {unit_cost:g}/unit, "
              f"price {unit_price:g}/unit.")

    # ------------------------------------------------------------------ sales
    def sell(self, product: str, quantity: float):
        """
        Record a sale using FIFO: consume the oldest batches first.
        Splits the sale across batches as needed, each part priced at the
        batch's own selling price. All-or-nothing: if there isn't enough
        total stock, nothing is sold.
        """
        if quantity <= 0:
            raise ValueError("Quantity must be greater than 0.")

        # Oldest batch first (lowest batch_id == earliest purchase).
        batches = self.conn.execute(
            "SELECT batch_id, remaining, unit_cost, unit_price FROM batches "
            "WHERE product = ? AND remaining > 0 ORDER BY batch_id ASC",
            (product,),
        ).fetchall()

        available = sum(b[1] for b in batches)
        if available < quantity:
            raise ValueError(
                f"Insufficient stock for '{product}': requested {quantity:g}, "
                f"only {available:g} available."
            )

        now = datetime.now().isoformat(timespec="seconds")
        remaining_to_sell = quantity
        total_revenue = 0.0
        total_cost = 0.0
        breakdown = []  # (batch_id, qty, unit_price, unit_cost, revenue, cost)

        for batch_id, remaining, unit_cost, unit_price in batches:
            if remaining_to_sell <= 0:
                break
            take = min(remaining, remaining_to_sell)
            revenue = take * unit_price
            cost = take * unit_cost
            total_revenue += revenue
            total_cost += cost
            breakdown.append((batch_id, take, unit_price, unit_cost, revenue, cost))
            remaining_to_sell -= take

        # Persist sale, batch breakdown, and stock deductions atomically.
        cur = self.conn.cursor()
        cur.execute(
            "INSERT INTO sales (product, quantity, revenue, cost, sold_at) "
            "VALUES (?, ?, ?, ?, ?)",
            (product, quantity, total_revenue, total_cost, now),
        )
        sale_id = cur.lastrowid

        for batch_id, take, unit_price, unit_cost, revenue, cost in breakdown:
            cur.execute(
                "INSERT INTO sale_batches (sale_id, batch_id, quantity, "
                "unit_price, unit_cost, revenue, cost) "
                "VALUES (?, ?, ?, ?, ?, ?, ?)",
                (sale_id, batch_id, take, unit_price, unit_cost, revenue, cost),
            )
            cur.execute(
                "UPDATE batches SET remaining = remaining - ? WHERE batch_id = ?",
                (take, batch_id),
            )
        self.conn.commit()

        # Friendly output showing the batch split.
        print(f"OK: Sold {quantity:g} units of '{product}' "
              f"(total revenue {total_revenue:g}, total cost {total_cost:g}, "
              f"profit {total_revenue - total_cost:g}).")
        print("    Batch breakdown (FIFO):")
        for batch_id, take, unit_price, unit_cost, revenue, cost in breakdown:
            print(f"      - Batch #{batch_id}: {take:g} unit(s) @ "
                  f"{unit_price:g} = {revenue:g} (cost {cost:g})")

    # -------------------------------------------------------------- reporting

    def stock(self):
        """Show all batches with remaining quantity and selling prices."""
        rows = self.conn.execute(
            "SELECT batch_id, product, remaining, unit_cost, unit_price, "
            "created_at FROM batches ORDER BY product, batch_id"
        ).fetchall()

        if not rows:
            print("No batches in stock. Use 'add_purchase' to add some.")
            return

        print(f"{'Batch':>5}  {'Product':<15} {'Remaining':>9} "
              f"{'Cost':>8} {'Price':>8}  Purchased At")
        print("-" * 70)
        for batch_id, product, remaining, cost, price, created in rows:
            status = "" if remaining > 0 else "  (depleted)"
            print(f"{batch_id:>5}  {product:<15} {remaining:>9g} "
                  f"{cost:>8g} {price:>8g}  {created}{status}")
        print("-" * 70)

    def sales_report(self):
        """Show all sales with their per-batch breakdown and revenue."""
        sales = self.conn.execute(
            "SELECT sale_id, product, quantity, revenue, cost, sold_at "
            "FROM sales ORDER BY sale_id"
        ).fetchall()

        if not sales:
            print("No sales recorded yet. Use 'sell' to record one.")
            return

        total_revenue = 0.0
        total_cost = 0.0
        for sale_id, product, quantity, revenue, cost, sold_at in sales:
            total_revenue += revenue
            total_cost += cost
            print(f"Sale #{sale_id} | {sold_at} | Sold {quantity:g} of "
                  f"'{product}' | Revenue {revenue:g} | Cost {cost:g} | "
                  f"Profit {revenue - cost:g}")
            details = self.conn.execute(
                "SELECT sb.batch_id, sb.quantity, sb.unit_price, sb.revenue "
                "FROM sale_batches sb WHERE sb.sale_id = ? ORDER BY sb.id",
                (sale_id,),
            ).fetchall()
            for batch_id, qty, unit_price, rev in details:
                print(f"    from Batch #{batch_id}: {qty:g} unit(s) @ "
                      f"{unit_price:g} = {rev:g}")
            print()

        print("-" * 50)
        print(f"TOTALS: Revenue {total_revenue:g} | Cost {total_cost:g} | "
              f"Profit {total_revenue - total_cost:g}")

    def close(self):
        self.conn.close()


# ---------------------------------------------------------------------- CLI

USAGE = (
    "Usage:\n"
    "  add_purchase <product> <qty> <unit_cost> <unit_price>\n"
    "  sell <product> <qty>\n"
    "  stock\n"
    "  sales_report\n"
    "  help\n"
    "\n"
    "Example: python fifo_inventory.py add_purchase A 10 3 5\n"
    "         python fifo_inventory.py sell A 3\n"
    "         python fifo_inventory.py stock\n"
    "         python fifo_inventory.py sales_report\n"
)


def to_float(value: str, name: str) -> float:
    """Convert a CLI argument to float with a friendly error message."""
    try:
        return float(value)
    except ValueError:
        raise ValueError(f"'{name}' must be a number, got '{value}'.")


def main(argv):
    if not argv or argv[0] in ("help", "-h", "--help"):
        print(USAGE)
        return 0

    command = argv[0]
    mgr = InventoryManager()
    try:
        if command == "add_purchase":
            if len(argv) != 5:
                raise ValueError("add_purchase needs: <product> <qty> "
                                 "<unit_cost> <unit_price>")
            mgr.add_purchase(argv[1], to_float(argv[2], "qty"),
                             to_float(argv[3], "unit_cost"),
                             to_float(argv[4], "unit_price"))
        elif command == "sell":
            if len(argv) != 3:
                raise ValueError("sell needs: <product> <qty>")
            mgr.sell(argv[1], to_float(argv[2], "qty"))
        elif command == "stock":
            mgr.stock()
        elif command == "sales_report":
            mgr.sales_report()
        else:
            print(f"Unknown command: {command}\n")
            print(USAGE)
            return 1
    except ValueError as e:
        print(f"Error: {e}")
        return 1
    finally:
        mgr.close()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
